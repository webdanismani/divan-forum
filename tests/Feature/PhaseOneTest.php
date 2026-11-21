<?php

namespace Tests\Feature;

use App\Forum\Installer;
use App\Forum\Mailing;
use App\Forum\Permissions\PermissionResolver;
use App\Forum\Posting;
use App\Forum\Settings;
use App\Forum\Setup\EnvFile;
use App\Forum\Tasks;
use App\Forum\Wallet;
use App\Models\Attachment;
use App\Models\Node;
use App\Models\Payment;
use App\Models\Post;
use App\Models\PromotionOption;
use App\Models\Thread;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\UserUpgrade;
use App\Models\UserUpgradePurchase;
use App\Models\Warning;
use App\Models\WarningType;
use App\Notifications\VerifyEmailAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** Attachments, warnings, bulk moderation, e-mail confirmation, wallet, promotion, packages, installer. */
class PhaseOneTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $moderator;

    protected User $member;

    protected Node $forum;

    protected function setUp(): void
    {
        parent::setUp();

        app(Installer::class)->installDefaults();
        Storage::fake('local');
        $this->admin = User::factory()->inGroup('admin')->create();
        $this->moderator = User::factory()->inGroup('moderator')->create();
        $this->member = User::factory()->create();
        $this->forum = Node::where('title', 'Genel Sohbet')->firstOrFail();
        app(Settings::class)->set(['posting.flood_seconds' => 0]);
    }

    protected function thread(string $title = 'Deneme konusu', ?User $author = null): Thread
    {
        return app(Posting::class)->createThread($this->forum, $author ?? $this->member, $title, 'İlk mesaj');
    }

    public function test_attachments_are_uploaded_attached_and_protected(): void
    {
        $key = str_repeat('a', 32);
        $upload = fn (UploadedFile $file) => $this->post(route('attachments.store'), ['file' => $file, 'key' => $key, 'node_id' => $this->forum->id], ['Accept' => 'application/json']);

        $upload(UploadedFile::fake()->image('foto.png', 900, 600))->assertUnauthorized();

        $this->actingAs($this->member);
        // Dangerous types are refused whatever their content, images are checked for real.
        $upload(UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;'))->assertStatus(422);
        $upload(UploadedFile::fake()->createWithContent('page.svg', '<svg onload="alert(1)"/>'))->assertStatus(422);
        $upload(UploadedFile::fake()->createWithContent('fake.png', 'not an image'))->assertStatus(422);

        $image = $upload(UploadedFile::fake()->image('foto.png', 900, 600))->assertOk()->json();
        $pdf = $upload(UploadedFile::fake()->create('belge.pdf', 20, 'application/pdf'))->assertOk()->json();
        $this->assertTrue($image['is_image']);
        $this->assertSame('[attach]'.$image['id'].'[/attach]', $image['bbcode']);
        $stored = Attachment::findOrFail($image['id']);
        $this->assertNotNull($stored->thumb_path);
        $this->assertStringEndsWith('.bin', $stored->disk_path);
        Storage::disk('local')->assertExists($stored->disk_path);

        // Until it is posted only the uploader can open it.
        $this->get($stored->url())->assertOk();
        $this->actingAs($this->moderator)->get($stored->url())->assertNotFound();

        $this->actingAs($this->member)->post(route('threads.store', $this->forum), [
            'title' => 'Ekli konu', 'body' => "Bakın:\n".$image['bbcode'], 'attachment_key' => $key,
        ])->assertRedirect();
        $thread = Thread::where('title', 'Ekli konu')->firstOrFail();
        $this->assertSame(2, Attachment::where('post_id', $thread->first_post_id)->count());

        // The image is shown inline, the other file is listed under the post; guests may look, too.
        auth()->logout();
        $page = $this->get(route('threads.show', $thread))->assertOk()->assertSee('bb-attach-img', false)->assertSee('belge.pdf');
        $this->assertSame(1, substr_count($page->getContent(), 'class="bb-img"'));
        $this->get($stored->url())->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get($stored->url(true))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $download = $this->get(Attachment::findOrFail($pdf['id'])->url())->assertOk();
        $this->assertStringContainsString('attachment', $download->headers->get('Content-Disposition'));
        $this->assertSame('application/octet-stream', $download->headers->get('Content-Type'));

        // A forum guests cannot read protects its files as well.
        UserGroup::system('guest')->update(['permissions' => ['profile.view' => 'allow']]);
        $this->get($stored->url())->assertRedirect(route('login'));

        // Others cannot delete it; hard-deleting the thread removes the files.
        $this->actingAs(User::factory()->create())->delete(route('attachments.destroy', $stored))->assertForbidden();
        app(Posting::class)->setThreadState($thread, 'deleted', $this->admin, hard: true);
        $this->assertSame(0, Attachment::count());
        Storage::disk('local')->assertMissing($stored->disk_path);
        Storage::disk('local')->assertMissing($stored->thumb_path);

        // Uploads that are never posted are cleaned up.
        $orphan = $this->actingAs($this->member)->post(route('attachments.store'), [
            'file' => UploadedFile::fake()->image('yarim.png', 100, 100), 'key' => str_repeat('b', 32), 'node_id' => $this->forum->id,
        ], ['Accept' => 'application/json'])->assertOk()->json();
        $this->travel(2)->days();
        app(Tasks::class)->runDue(force: true);
        $this->assertNull(Attachment::find($orphan['id']));
    }

    public function test_warnings_add_points_ban_and_expire(): void
    {
        $type = WarningType::where('points', 3)->firstOrFail();

        $this->actingAs($this->member)->post(route('members.warn', $this->moderator), ['warning_type_id' => $type->id])->assertForbidden();

        $this->actingAs($this->moderator);
        $this->post(route('members.warn', $this->admin), ['warning_type_id' => $type->id])->assertForbidden();
        $this->post(route('members.warn', $this->member), ['warning_type_id' => $type->id, 'note' => 'İlk uyarı'])->assertRedirect();

        $this->member->refresh();
        $this->assertSame(3, $this->member->warning_points);
        $this->assertFalse($this->member->isBanned());
        $this->assertSame(1, $this->member->notifications()->count());
        $this->get(route('members.show', $this->member))->assertOk()->assertSee($type->title)->assertSee('İlk uyarı');

        // The member sees the warning but not the moderator's note; others see nothing.
        $this->actingAs($this->member)->get(route('members.show', $this->member))->assertOk()->assertSee($type->title)->assertDontSee('İlk uyarı');
        $this->actingAs(User::factory()->create())->get(route('members.show', $this->member))->assertOk()->assertDontSee($type->title);

        // 5 points: banned for a week (default rule).
        $this->actingAs($this->moderator)->post(route('members.warn', $this->member), ['warning_type_id' => $type->id])->assertRedirect();
        $this->member->refresh();
        $this->assertSame(6, $this->member->warning_points);
        $this->assertTrue($this->member->isBanned());
        $this->assertEqualsWithDelta(7, now()->diffInDays($this->member->banned_until), 0.01);

        // Points run out on their own; removing a warning takes its points away at once.
        $this->travel($type->expiry_days + 1)->days();
        app(Tasks::class)->runDue(force: true);
        $this->assertSame(0, $this->member->fresh()->warning_points);

        // Free titles and points are for administrators: a moderator picks one of the defined types.
        $this->post(route('members.warn', $this->member), ['title' => 'Özel uyarı', 'points' => 100, 'expiry_days' => 0])->assertSessionHasErrors('warning_type_id');
        $this->assertSame(0, $this->member->fresh()->warning_points);

        // A moderator does not warn another member of the team, and nobody lifts a warning of their own.
        $colleague = User::factory()->inGroup('moderator')->create();
        $this->post(route('members.warn', $colleague), ['warning_type_id' => $type->id])->assertForbidden();
        $this->assertSame(0, $colleague->fresh()->warning_points);
        $this->actingAs($this->admin)->post(route('members.warn', $colleague), ['warning_type_id' => $type->id])->assertRedirect();
        $own = Warning::where('user_id', $colleague->id)->firstOrFail();
        $this->actingAs($colleague)->delete(route('warnings.destroy', $own))->assertForbidden();
        $this->assertSame($type->points, $colleague->fresh()->warning_points);

        $this->actingAs($this->admin)->post(route('members.warn', $this->member), ['title' => 'Özel uyarı', 'points' => 2, 'expiry_days' => 0])->assertRedirect();
        $this->assertSame(2, $this->member->fresh()->warning_points);
        // A moderator lifts only what they issued themselves.
        $custom = Warning::where('user_id', $this->member->id)->latest('id')->firstOrFail();
        $this->actingAs($this->moderator)->delete(route('warnings.destroy', $custom))->assertForbidden();
        $this->actingAs($this->admin)->delete(route('warnings.destroy', $custom))->assertRedirect();
        $this->assertSame(0, $this->member->fresh()->warning_points);

        $this->actingAs($this->admin)->get(route('admin.warnings'))->assertOk()->assertSee($type->title);
        $this->post(route('admin.warnings.types.save'), ['title' => 'Çift hesap', 'points' => 4, 'expiry_days' => 0])->assertRedirect();
        $this->post(route('admin.warnings.actions.save'), ['points' => 20, 'ban_days' => 0])->assertRedirect();
        $this->assertSame(4, WarningType::where('title', 'Çift hesap')->value('points'));
    }

    public function test_threads_and_posts_can_be_moderated_in_bulk(): void
    {
        $posting = app(Posting::class);
        $other = User::factory()->create();
        $a = $this->thread('Birinci');
        $b = $this->thread('İkinci', $other);
        $c = $this->thread('Üçüncü');
        $replyB = $posting->reply($b, $this->member, 'İkinciye cevap');
        $target = Node::where('title', 'Tanışma')->firstOrFail();

        $this->actingAs($this->member)->post(route('mod.threads', $this->forum), ['threads' => [$a->id], 'action' => 'lock'])->assertForbidden();
        $this->get(route('nodes.show', $this->forum))->assertOk()->assertDontSee('thread-mod-form');

        $this->actingAs($this->moderator);
        $this->get(route('nodes.show', $this->forum))->assertOk()->assertSee('thread-mod-form');

        $this->post(route('mod.threads', $this->forum), ['threads' => [$a->id, $b->id], 'action' => 'lock'])->assertRedirect();
        $this->assertTrue($a->fresh()->is_locked && $b->fresh()->is_locked);
        $this->assertFalse($c->fresh()->is_locked);

        // Copy: the original stays, the copy has the same posts.
        $this->post(route('mod.threads', $this->forum), ['threads' => [$b->id], 'action' => 'copy', 'node_id' => $target->id])->assertRedirect();
        $copy = Thread::where('node_id', $target->id)->where('title', 'İkinci')->firstOrFail();
        $this->assertSame(2, $copy->posts()->count());
        $this->assertSame(1, $copy->reply_count);
        $this->assertSame(1, $target->fresh()->thread_count);

        // Merge: everything ends up in the oldest thread, counters follow.
        $this->post(route('mod.threads', $this->forum), ['threads' => [$a->id], 'action' => 'merge'])->assertSessionHasErrors('threads');
        $this->post(route('mod.threads', $this->forum), ['threads' => [$b->id, $a->id], 'action' => 'merge'])->assertRedirect(route('threads.show', $a));
        $this->assertNull(Thread::find($b->id));
        $this->assertSame(3, $a->fresh()->posts()->count());
        $this->assertSame(2, $a->fresh()->reply_count);
        $this->assertSame($replyB->id, $a->fresh()->last_post_id);
        $this->assertSame(2, $this->forum->fresh()->thread_count);
        $this->assertSame(4, $this->forum->fresh()->post_count);

        // Split the last post off into its own thread.
        $this->post(route('mod.posts', $a), ['posts' => [$replyB->id], 'action' => 'split', 'title' => 'Ayrılan konu'])->assertRedirect();
        $split = Thread::where('title', 'Ayrılan konu')->firstOrFail();
        $this->assertSame([$replyB->id], $split->posts()->pluck('id')->all());
        $this->assertSame($this->member->id, $split->user_id);
        $this->assertSame(1, $a->fresh()->reply_count);
        $this->assertSame(3, $this->forum->fresh()->thread_count);

        // Move it back by address; the emptied thread disappears.
        $this->post(route('mod.posts', $split), ['posts' => [$replyB->id], 'action' => 'move', 'target' => 'no such thread'])->assertSessionHasErrors('target');
        $this->post(route('mod.posts', $split), ['posts' => [$replyB->id], 'action' => 'move', 'target' => route('threads.show', $c)])->assertRedirect(route('threads.show', $c));
        $this->assertNull(Thread::find($split->id));
        $this->assertSame(1, $c->fresh()->reply_count);

        // Bulk delete and restore of posts never touches the first post.
        $this->post(route('mod.posts', $c), ['posts' => [$c->first_post_id, $replyB->id], 'action' => 'delete'])->assertRedirect();
        $this->assertSame('visible', Post::find($c->first_post_id)->state);
        $this->assertSame('deleted', $replyB->fresh()->state);
        $this->post(route('mod.posts', $c), ['posts' => [$replyB->id], 'action' => 'restore'])->assertRedirect();
        $this->assertSame('visible', $replyB->fresh()->state);

        $this->post(route('mod.threads', $this->forum), ['threads' => [$a->id, $c->id], 'action' => 'move', 'node_id' => $target->id])->assertRedirect();
        $this->assertSame(0, $this->forum->fresh()->thread_count);
        $this->assertSame(3, $target->fresh()->thread_count);
    }

    public function test_new_members_confirm_their_email_address(): void
    {
        Notification::fake();
        app(Settings::class)->set(['registration.verify_email' => true]);

        $fresh = User::factory()->create(['email_verified_at' => null]);
        $thread = $this->thread();

        $this->actingAs($fresh);
        $this->get('/')->assertOk()->assertSee('E-posta adresini doğrula');
        $this->get(route('threads.create', $this->forum))->assertForbidden();
        $this->post(route('posts.store', $thread), ['body' => 'Merhaba'])->assertForbidden();

        $this->get(route('verification.notice'))->assertOk()->assertSee($fresh->email);
        $this->post(route('verification.send'))->assertRedirect();
        Notification::assertSentTo($fresh, VerifyEmailAddress::class);

        $link = fn (string $hash) => URL::temporarySignedRoute('verification.verify', now()->addDay(), ['id' => $fresh->id, 'hash' => $hash]);
        $this->get(route('verification.verify', ['id' => $fresh->id, 'hash' => sha1($fresh->email)]))->assertForbidden();
        $this->get($link('wrong'))->assertForbidden();
        $this->assertFalse($fresh->fresh()->isVerified());

        $this->get($link(sha1($fresh->email)))->assertRedirect(route('forum.index'));
        $this->assertTrue($fresh->fresh()->isVerified());

        app(PermissionResolver::class)->flush();
        $this->actingAs($fresh->fresh())->post(route('posts.store', $thread), ['body' => 'Merhaba'])->assertRedirect();
        $this->get('/')->assertOk()->assertDontSee('E-posta adresini doğrula');

        // Changing the address asks for confirmation again.
        $this->put(route('account.security'), ['current_password' => 'password', 'email' => 'yeni-adres@example.com'])->assertRedirect(route('verification.notice'));
        $this->assertFalse($fresh->fresh()->isVerified());
    }

    public function test_wallet_top_up_thread_promotion_and_membership_packages(): void
    {
        $settings = app(Settings::class);
        $this->actingAs($this->member);

        $this->get(route('wallet.index'))->assertOk()->assertSee('Henüz bir ödeme yöntemi tanımlanmadı');
        $settings->set(['ads.transfer_info' => 'IBAN: TR00 0000']);
        $billing = ['billing_name' => 'Ayşe Yılmaz', 'billing_phone' => '05550000000', 'billing_address' => 'İzmir'];

        $this->post(route('wallet.topup'), ['amount' => 5, 'method' => 'transfer'] + $billing)->assertSessionHasErrors('amount');
        $this->post(route('wallet.topup'), ['amount' => 500, 'method' => 'card'] + $billing)->assertSessionHasErrors('method');
        $this->post(route('wallet.topup'), ['amount' => 500, 'method' => 'transfer'] + $billing)->assertRedirect();
        $payment = Payment::firstOrFail();
        $this->get(route('payments.show', $payment))->assertOk()->assertSee('IBAN: TR00 0000');
        $this->post(route('payments.transfer', $payment))->assertRedirect();
        $this->assertSame('review', $payment->fresh()->status);
        $this->assertSame(0.0, $this->member->fresh()->balance);
        $this->post(route('admin.payments.action', $payment), ['action' => 'approve'])->assertForbidden();

        // The administrator confirms the transfer: the balance is credited exactly once.
        $this->actingAs($this->admin)->get(route('admin.payments.index'))->assertOk()->assertSee('Bakiye yükleme');
        $this->post(route('admin.payments.action', $payment), ['action' => 'approve'])->assertRedirect();
        $this->post(route('admin.payments.action', $payment), ['action' => 'approve'])->assertRedirect();
        $this->assertSame(500.0, $this->member->fresh()->balance);
        $this->get(route('admin.wallet'))->assertOk()->assertSee($this->member->username);

        // Manual adjustment; a balance can never go below zero.
        $this->post(route('admin.users.balance', $this->member), ['amount' => -1000, 'note' => 'Hata'])->assertSessionHasErrors('balance');
        $this->post(route('admin.users.balance', $this->member), ['amount' => 100, 'note' => 'Hediye'])->assertRedirect();
        $this->assertSame(600.0, $this->member->fresh()->balance);

        // ---- Thread promotion ----
        $mine = $this->thread('Benim konum');
        $old = $this->thread('Eski konu');
        $theirs = $this->thread('Başkasının konusu', $this->moderator);
        Thread::whereKey($mine->id)->update(['last_post_at' => now()->subDays(3)]);
        $this->forum->update(['require_approval' => false]);

        $sticky = PromotionOption::where('type', 'sticky')->where('days', 7)->firstOrFail();
        $highlight = PromotionOption::where('type', 'highlight')->where('days', 7)->firstOrFail();
        $bump = PromotionOption::where('type', 'bump')->firstOrFail();

        $this->actingAs($this->member);
        $this->get(route('threads.promote', $theirs))->assertForbidden();
        $this->get(route('threads.promote', $mine))->assertOk()->assertSee('En üste sabitle')->assertSee('150 ₺');

        $this->post(route('threads.promote.buy', $mine), ['option_id' => $sticky->id])->assertRedirect(route('threads.show', $mine));
        $this->post(route('threads.promote.buy', $mine), ['option_id' => $highlight->id])->assertRedirect();
        $mine->refresh();
        $this->assertTrue($mine->isPinned() && $mine->isHighlighted());
        $this->assertSame(600.0 - 150 - 60, $this->member->fresh()->balance);

        // Pinned first although it is the least recently answered thread; the title is highlighted.
        $list = $this->get(route('nodes.show', $this->forum))->assertOk()->assertSee('Öne çıkan')->getContent();
        $this->assertLessThan(strpos($list, 'Eski konu'), strpos($list, 'Benim konum'));
        $this->assertStringContainsString('thread-row thread-grid sticky', $list);

        // Buying again extends the period; a bump moves an old thread up.
        $this->post(route('threads.promote.buy', $mine), ['option_id' => $sticky->id])->assertRedirect();
        $this->assertEqualsWithDelta(14, now()->diffInDays($mine->fresh()->promoted_until), 0.01);
        Thread::whereKey($old->id)->update(['last_post_at' => now()->subDays(9)]);
        $this->post(route('threads.promote.buy', $old), ['option_id' => $bump->id])->assertRedirect();
        $this->assertTrue($old->fresh()->last_post_at->gt(now()->subMinute()));

        // Not enough balance: nothing happens.
        app(Wallet::class)->debit($this->member, $this->member->fresh()->balance, 'adjustment', 'Sıfırla');
        $this->post(route('threads.promote.buy', $old), ['option_id' => $sticky->id])->assertSessionHasErrors('balance');
        $this->assertFalse($old->fresh()->isPinned());

        // When the period is over the thread is an ordinary thread again.
        $this->travel(15)->days();
        $this->assertFalse($mine->fresh()->isPinned());

        // ---- Membership packages ----
        $this->actingAs($this->admin)->post(route('admin.groups.store'), ['name' => 'VIP', 'badge' => 'VIP', 'color' => '#c08a00', 'perms' => ['mod.viewDeleted' => 'allow']])->assertRedirect();
        $vip = UserGroup::where('name', 'VIP')->firstOrFail();
        $this->post(route('admin.upgrades.save'), ['title' => 'Sahte', 'price' => 1, 'days' => 30, 'user_group_id' => UserGroup::system('admin')->id, 'is_active' => '1'])->assertSessionHasErrors('user_group_id');
        $this->post(route('admin.upgrades.save'), ['title' => 'VIP Üyelik', 'price' => 200, 'days' => 30, 'user_group_id' => $vip->id, 'is_active' => '1'])->assertRedirect();
        $upgrade = UserUpgrade::firstOrFail();
        $this->get(route('admin.upgrades'))->assertOk()->assertSee('VIP Üyelik');
        $this->get(route('admin.promotions'))->assertOk()->assertSee('Benim konum');

        $this->actingAs($this->member);
        $this->get(route('upgrades.index'))->assertOk()->assertSee('VIP Üyelik')->assertSee('200 ₺');
        $this->post(route('upgrades.buy', $upgrade), ['method' => 'balance'])->assertSessionHasErrors('balance');
        $this->assertFalse(forum_can('mod.viewDeleted'));

        app(Wallet::class)->credit($this->member, 450, 'adjustment', 'Test');
        $this->post(route('upgrades.buy', $upgrade), ['method' => 'balance'])->assertRedirect(route('upgrades.index'));
        $this->assertTrue($this->member->fresh()->groups->contains($vip->id));
        $this->assertSame(250.0, $this->member->fresh()->balance);

        // Bought again: the period is extended, not restarted.
        $this->post(route('upgrades.buy', $upgrade), ['method' => 'balance'])->assertRedirect();
        $purchase = $this->member->fresh()->load('groups')->groups->firstWhere('id', $vip->id);
        $this->assertNotNull($purchase);
        $this->assertEqualsWithDelta(60, now()->diffInDays(UserUpgradePurchase::firstOrFail()->ends_at), 0.01);

        // When the period ends the member leaves the group.
        $this->travel(61)->days();
        app(Tasks::class)->runDue(force: true);
        $this->assertFalse($this->member->fresh()->groups->contains($vip->id));
        $this->assertSame('expired', UserUpgradePurchase::firstOrFail()->status);
    }

    public function test_installer_is_closed_once_installed_and_maintenance_page_works(): void
    {
        // The board has members: the installer must not answer.
        $this->get('/install')->assertNotFound();
        $this->post('/install', ['admin_username' => 'x'])->assertNotFound();

        $this->assertSame("APP_NAME=\"My Forum\"\nAPP_KEY=base64:abc=\n# comment\nDB_PASSWORD=\"p\\\"a\\\$s#1\"\nNEW=1\n", EnvFile::render(
            "APP_NAME=Laravel\nAPP_KEY=\n# comment\n# DB_PASSWORD=\n",
            ['APP_NAME' => 'My Forum', 'APP_KEY' => 'base64:abc=', 'DB_PASSWORD' => 'p"a$s#1', 'NEW' => 1],
        ));
        $this->assertSame('true', EnvFile::quote(true));
        $this->assertSame('"a b"', EnvFile::quote("a\n b"));

        $this->actingAs($this->member)->get(route('admin.update'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.update'))->assertOk()->assertSee('attachments.purge');
        $this->post(route('admin.update.tasks'))->assertRedirect();
        $this->post(route('admin.update.upload'), ['package' => UploadedFile::fake()->create('x.zip', 5, 'application/zip'), 'current_password' => 'wrong'])
            ->assertSessionHasErrors('current_password');

        $this->get(route('admin.settings.edit'))->assertOk()->assertSee('SMTP sunucu adresi')->assertSee('type="password"', false);
        $this->post(route('admin.settings.test-mail'))->assertSessionHas('error');
    }

    public function test_secret_settings_are_kept_when_left_empty(): void
    {
        $settings = app(Settings::class);
        $defaults = collect($settings->definitions())
            ->reject(fn (array $def) => $def['type'] === 'bool')
            ->mapWithKeys(fn (array $def, string $key) => [str_replace('.', '__', $key) => $def['default']])
            ->all();

        $this->actingAs($this->admin);
        $this->put(route('admin.settings.update'), ['mail__password' => 'gizli-smtp', 'mail__driver' => 'smtp', 'mail__host' => 'smtp.example.com', 'mail__from_address' => 'forum@example.com'] + $defaults)->assertSessionHasNoErrors();
        $this->assertSame('gizli-smtp', setting('mail.password'));

        // Saving the form again without typing the password keeps it, and it is never printed.
        $this->put(route('admin.settings.update'), ['mail__driver' => 'smtp', 'mail__host' => 'smtp.example.com'] + $defaults)->assertSessionHasNoErrors();
        $this->assertSame('gizli-smtp', setting('mail.password'));
        $this->get(route('admin.settings.edit'))->assertOk()->assertDontSee('gizli-smtp');

        Mailing::configure($settings);
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));
    }
}
