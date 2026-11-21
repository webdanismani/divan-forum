<?php

namespace Tests\Feature;

use App\Forum\Installer;
use App\Forum\Settings;
use App\Forum\Wallet;
use App\Models\Ad;
use App\Models\AdZone;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $member;

    protected AdZone $top;

    protected function setUp(): void
    {
        parent::setUp();

        app(Installer::class)->installDefaults();
        Storage::fake('public');
        $this->admin = User::factory()->inGroup('admin')->create();
        $this->member = User::factory()->create();
        $this->top = AdZone::where('position', 'top')->firstOrFail();
    }

    protected function order(array $overrides = []): array
    {
        return $overrides + [
            'zone_id' => $this->top->id,
            'days' => 7,
            'title' => 'Örnek Hosting',
            'target_url' => 'https://example.com/kampanya',
            'image' => UploadedFile::fake()->image('banner.png', 728, 90),
            'billing_name' => 'Ayşe Yılmaz',
            'billing_phone' => '05550000000',
            'billing_address' => 'Örnek Mah. 1. Sk. No: 1 İzmir',
        ];
    }

    protected function paytr(): void
    {
        app(Settings::class)->set(['ads.paytr_merchant_id' => '123456', 'ads.paytr_merchant_key' => 'anahtar', 'ads.paytr_merchant_salt' => 'tuz']);
    }

    protected function paytrNotice(string $orderId, string $status, int $total): array
    {
        $hash = base64_encode(hash_hmac('sha256', $orderId.'tuz'.$status.$total, 'anahtar', true));

        return ['merchant_oid' => $orderId, 'status' => $status, 'total_amount' => $total, 'hash' => $hash];
    }

    public function test_free_spaces_are_offered_and_prices_are_listed(): void
    {
        $sidebar = AdZone::where('position', 'sidebar')->firstOrFail();
        $response = $this->get('/')->assertOk()->assertSee('Buraya reklam verin')->assertSee(route('ads.index', ['zone' => 'top']), false);
        // One real-size placeholder for every free slot.
        $this->assertSame($sidebar->slots, substr_count($response->getContent(), route('ads.index', ['zone' => 'sidebar'])));

        $this->get(route('ads.index'))->assertOk()
            ->assertSee('Üst banner')->assertSee('Ana sayfa sağ sütun')
            ->assertSee('300 ₺')->assertSee('900 ₺')->assertSee('2.250 ₺');

        $this->post(route('ads.store'), $this->order())->assertRedirect(route('login'));

        app(Settings::class)->set(['ads.enabled' => false]);
        $this->get('/')->assertOk()->assertDontSee('Buraya reklam verin');
        $this->get(route('ads.index'))->assertNotFound();
    }

    public function test_member_orders_pays_by_card_and_the_ad_goes_live(): void
    {
        $this->paytr();
        Http::fake(['www.paytr.com/*' => Http::response(['status' => 'success', 'token' => 'TOKEN123'])]);

        $this->actingAs($this->member);

        // The banner has to match the zone, and the period has to be one that is on sale.
        $this->post(route('ads.store'), $this->order(['image' => UploadedFile::fake()->image('b.png', 300, 250)]))->assertSessionHasErrors('image');
        $this->post(route('ads.store'), $this->order(['days' => 3]))->assertSessionHasErrors('days');
        $this->post(route('ads.store'), $this->order(['target_url' => 'javascript:alert(1)']))->assertSessionHasErrors('target_url');
        $this->assertSame(0, Ad::count());

        $this->post(route('ads.store'), $this->order())->assertRedirect();
        $ad = Ad::firstOrFail();
        $this->assertSame('unpaid', $ad->status);
        $this->assertSame(300.0, $ad->price);
        $this->assertSame('Ayşe Yılmaz', $this->member->fresh()->billing_name);
        Storage::disk('public')->assertExists($ad->image_path);

        // Not shown before it is paid, and other members cannot open or pay the order.
        $this->get('/')->assertOk()->assertDontSee(route('ads.click', $ad), false);
        $other = User::factory()->create();
        $this->actingAs($other)->get(route('ads.show', $ad))->assertForbidden();
        $this->post(route('ads.pay', $ad), ['method' => 'card'])->assertForbidden();

        $this->actingAs($this->member)->get(route('ads.show', $ad))->assertOk()->assertSee('Kartla öde')->assertSee('Bakiyenizden ödeyin');
        $this->post(route('ads.pay', $ad), ['method' => 'card'])->assertRedirect();
        $payment = Payment::firstOrFail();
        $this->assertSame(['ad', $ad->id, 300.0, 'pending'], [$payment->purpose, $payment->subject_id, $payment->amount, $payment->status]);

        $this->actingAs($other)->get(route('payments.show', $payment))->assertForbidden();
        $this->actingAs($this->member)->get(route('payments.show', $payment))->assertOk()->assertDontSee('paytriframe');
        $this->get(route('payments.show', [$payment, 'pay' => 'card']))->assertOk()->assertSee('https://www.paytr.com/odeme/guvenli/TOKEN123', false);

        Http::assertSent(function ($request) {
            $data = $request->data();
            $expected = base64_encode(hash_hmac('sha256', '123456'.$data['user_ip'].$data['merchant_oid'].$data['email'].'30000'.$data['user_basket'].'10TL1'.'tuz', 'anahtar', true));

            return $request->url() === 'https://www.paytr.com/odeme/api/get-token'
                && $data['payment_amount'] === 30000
                && $data['user_name'] === 'Ayşe Yılmaz'
                && $data['paytr_token'] === $expected;
        });
        $orderId = $payment->fresh()->reference;
        $this->assertMatchesRegularExpression('/^P'.$payment->id.'R[A-Z0-9]+$/i', $orderId);

        // The notification is only believed when its signature is right.
        auth()->logout();
        $this->post(route('ads.paytr'), ['hash' => 'x'] + $this->paytrNotice($orderId, 'success', 30000))->assertStatus(400);
        $this->assertSame('unpaid', $ad->fresh()->status);

        $this->post(route('ads.paytr'), $this->paytrNotice($orderId, 'failed', 30000))->assertOk()->assertContent('OK');
        $this->assertSame('unpaid', $ad->fresh()->status);

        $this->post(route('ads.paytr'), $this->paytrNotice($orderId, 'success', 30000))->assertOk()->assertContent('OK');
        $ad->refresh();
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('active', $ad->status);
        $this->assertSame('card', $ad->payment_method);
        $this->assertSame(300.0, $ad->paid_amount);
        $this->assertTrue($ad->isLive());
        $this->assertEqualsWithDelta(7, $ad->starts_at->diffInDays($ad->ends_at), 0.01);
        $this->assertSame(1, $this->member->notifications()->count());

        // PayTR repeats notifications: the second one changes nothing.
        $endsAt = $ad->ends_at;
        $this->travel(2)->days();
        $this->post(route('ads.paytr'), $this->paytrNotice($orderId, 'success', 30000))->assertOk()->assertContent('OK');
        $this->assertTrue($endsAt->equalTo($ad->fresh()->ends_at));
        $this->assertSame(1, $this->member->notifications()->count());

        // Live: the banner is printed, the zone is full, impressions and clicks are counted.
        $this->get('/')->assertOk()->assertSee(route('ads.click', $ad), false)->assertDontSee(route('ads.index', ['zone' => 'top']), false);
        $this->assertSame(1, $ad->fresh()->impressions);
        $this->get(route('ads.click', $ad))->assertRedirect('https://example.com/kampanya');
        $this->assertSame(1, $ad->fresh()->clicks);

        $this->actingAs($this->member)->post(route('ads.store'), $this->order())->assertSessionHasErrors('zone_id');
        $this->get(route('ads.mine'))->assertOk()->assertSee('Örnek Hosting')->assertSee('Yayında');

        // After the period the banner disappears and the space is on sale again.
        $this->travel(6)->days();
        $this->get('/')->assertOk()->assertDontSee(route('ads.click', $ad), false)->assertSee('Buraya reklam verin');
        $this->get(route('ads.click', $ad))->assertRedirect(route('forum.index'));
    }

    public function test_paid_ads_wait_for_approval_when_auto_publish_is_off(): void
    {
        $this->paytr();
        app(Settings::class)->set(['ads.auto_publish' => false]);

        $this->actingAs($this->member)->post(route('ads.store'), $this->order())->assertRedirect();
        $ad = Ad::firstOrFail();
        $this->post(route('ads.pay', $ad), ['method' => 'card'])->assertRedirect();
        $payment = Payment::firstOrFail();

        // A payment that is short is recorded but nothing is delivered.
        $this->post(route('ads.paytr'), $this->paytrNotice('P'.$payment->id.'RSHORT1', 'success', 10000))->assertOk();
        $this->assertSame('review', $payment->fresh()->status);
        $this->assertSame('unpaid', $ad->fresh()->status);

        // The administrator accepts it: the ad is paid, but with auto-publish off it still waits.
        $this->actingAs($this->admin)->post(route('admin.payments.action', $payment), ['action' => 'approve'])->assertRedirect();
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('review', $ad->fresh()->status);
        $this->assertNotNull($ad->fresh()->paid_at);
        $this->get('/')->assertOk()->assertDontSee(route('ads.click', $ad), false);

        // A paid order keeps its place: nobody else can buy the last slot meanwhile.
        $this->actingAs($this->member)->post(route('ads.store'), $this->order())->assertSessionHasErrors('zone_id');
    }

    public function test_an_ad_can_be_paid_from_the_balance(): void
    {
        $this->actingAs($this->member)->post(route('ads.store'), $this->order())->assertRedirect();
        $ad = Ad::firstOrFail();

        $this->post(route('ads.pay', $ad), ['method' => 'balance'])->assertSessionHasErrors('balance');
        $this->assertSame('unpaid', $ad->fresh()->status);

        app(Wallet::class)->credit($this->member, 500, 'adjustment', 'Test');
        $this->post(route('ads.pay', $ad), ['method' => 'balance'])->assertRedirect(route('ads.show', $ad));

        $this->assertTrue($ad->fresh()->isLive());
        $this->assertSame('balance', $ad->fresh()->payment_method);
        $this->assertSame(200.0, $this->member->fresh()->balance);
        $this->post(route('ads.pay', $ad), ['method' => 'balance'])->assertForbidden();
    }

    public function test_bank_transfer_and_the_admin_manager(): void
    {
        app(Settings::class)->set(['wallet.enabled' => false]);
        $this->actingAs($this->member)->post(route('ads.store'), $this->order())->assertRedirect();
        $ad = Ad::firstOrFail();

        // No payment method configured yet.
        $this->get(route('ads.show', $ad))->assertOk()->assertSee('Henüz bir ödeme yöntemi tanımlanmadı');
        $this->post(route('ads.transfer', $ad))->assertForbidden();

        app(Settings::class)->set(['ads.transfer_info' => "Banka: Örnek Bank\nIBAN: TR00 0000"]);
        $this->get(route('ads.show', $ad))->assertOk()->assertSee('IBAN: TR00 0000')->assertDontSee('Kartla öde');
        $this->post(route('ads.transfer', $ad))->assertRedirect();
        $this->assertSame('review', $ad->fresh()->status);
        $this->assertSame('transfer', $ad->fresh()->payment_method);
        $this->delete(route('ads.destroy', $ad))->assertForbidden();

        // Admin pages are closed to members.
        $this->get(route('admin.ads.index'))->assertForbidden();
        $this->post(route('admin.ads.action', $ad), ['action' => 'approve'])->assertForbidden();
        $this->put(route('admin.ad-zones.update', $this->top), [])->assertForbidden();

        $this->actingAs($this->admin);
        $this->get(route('admin.ads.index'))->assertOk()->assertSee('Örnek Hosting')->assertSee('Havale bildirildi');
        $this->get(route('admin.ads.index', ['status' => 'review']))->assertOk()->assertSee('Örnek Hosting');
        $this->get(route('admin.ads.edit', $ad))->assertOk()->assertSee('Ayşe Yılmaz');
        $this->get(route('admin.ads.create'))->assertOk();
        $this->get(route('admin.ad-zones.index'))->assertOk()->assertSee('Üst banner');
        $this->get(route('admin.ad-zones.edit', $this->top))->assertOk();
        $this->get(route('admin.dashboard'))->assertOk();

        $this->post(route('admin.ads.action', $ad), ['action' => 'approve'])->assertRedirect();
        $ad->refresh();
        $this->assertTrue($ad->isLive());
        $this->assertNotNull($ad->paid_at);
        $this->assertSame(300.0, $ad->paid_amount);

        $this->post(route('admin.ads.action', $ad), ['action' => 'pause'])->assertRedirect();
        $this->get('/')->assertOk()->assertDontSee(route('ads.click', $ad), false);
        $this->post(route('admin.ads.action', $ad), ['action' => 'resume'])->assertRedirect();
        $this->get('/')->assertOk()->assertSee(route('ads.click', $ad), false);

        $this->put(route('admin.ads.update', $ad), [
            'zone_id' => $this->top->id, 'title' => 'Yeni başlık', 'target_url' => 'https://example.org', 'days' => 7, 'price' => 300,
            'starts_at' => now()->subDay()->format('Y-m-d\TH:i'), 'ends_at' => now()->addDays(20)->format('Y-m-d\TH:i'),
        ])->assertRedirect();
        $this->assertSame('Yeni başlık', $ad->fresh()->title);
        $this->assertTrue($ad->fresh()->ends_at->isAfter(now()->addDays(19)));

        $this->post(route('admin.ads.action', $ad), ['action' => 'reject', 'admin_note' => 'Uygun değil'])->assertRedirect();
        $this->assertSame('rejected', $ad->fresh()->status);
        $this->assertSame(2, $this->member->notifications()->count());

        // Zones: sizes, slots and the price list.
        $this->put(route('admin.ad-zones.update', $this->top), [
            'title' => 'Üst alan', 'description' => 'Menü altı', 'width' => 970, 'height' => 90, 'slots' => 2, 'is_active' => '1',
            'days' => [30, 15, null], 'price' => [1000, '550.50', null],
        ])->assertRedirect();
        $this->top->refresh();
        $this->assertSame([15 => 550.5, 30 => 1000.0], $this->top->priceList());
        $this->assertSame(2, $this->top->slots);
        $this->get(route('ads.index'))->assertOk()->assertSee('Üst alan')->assertSee('550,50 ₺');

        // An ad added by the admin is live at once, without payment.
        $this->post(route('admin.ads.store'), [
            'zone_id' => $this->top->id, 'title' => 'Ev reklamı', 'target_url' => 'https://example.net', 'days' => 10,
            'image' => UploadedFile::fake()->image('ev.png', 970, 90),
        ])->assertRedirect();
        $house = Ad::where('title', 'Ev reklamı')->firstOrFail();
        $this->assertTrue($house->isLive());
        $this->assertNull($house->paid_at);

        $path = $house->image_path;
        $this->delete(route('admin.ads.destroy', $house))->assertRedirect();
        $this->assertNull(Ad::find($house->id));
        Storage::disk('public')->assertMissing($path);
    }
}
