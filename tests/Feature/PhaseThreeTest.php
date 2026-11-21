<?php

namespace Tests\Feature;

use App\Forum\Community;
use App\Forum\Installer;
use App\Forum\Permissions\PermissionResolver;
use App\Forum\Posting;
use App\Forum\Rewards;
use App\Forum\Security\Totp;
use App\Forum\Security\TwoFactor;
use App\Forum\Settings;
use App\Forum\Spam\Guard;
use App\Forum\Tasks;
use App\Models\Badge;
use App\Models\Ban;
use App\Models\Node;
use App\Models\ProfileField;
use App\Models\ProfilePost;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Two-step sign-in, social sign-in, bans, ignoring/following, profile posts, fields, titles, badges, promotions. */
class PhaseThreeTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $member;

    protected User $other;

    protected Node $forum;

    protected function setUp(): void
    {
        parent::setUp();

        app(Installer::class)->installDefaults();
        $this->admin = User::factory()->inGroup('admin')->create();
        $this->member = User::factory()->create();
        $this->other = User::factory()->create();
        $this->forum = Node::where('title', 'Genel Sohbet')->firstOrFail();
        app(Settings::class)->set(['posting.flood_seconds' => 0]);
    }

    public function test_totp_matches_the_reference_vectors(): void
    {
        // RFC 6238 appendix B, SHA-1, secret "12345678901234567890" (the last 6 of the 8 digits).
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
        $this->assertSame('12345678901234567890', Totp::base32Decode($secret));

        foreach ([59 => '287082', 1111111109 => '081804', 1234567890 => '005924', 2000000000 => '279037'] as $time => $code) {
            $this->assertSame($code, Totp::codeAt($secret, Totp::step($time)));
        }

        $step = Totp::step(1234567890);
        $this->assertSame($step, Totp::verify($secret, '005 924', null, 1234567890));
        $this->assertSame($step, Totp::verify($secret, '005924', null, 1234567890 + 30));   // one step of clock drift
        $this->assertNull(Totp::verify($secret, '005924', null, 1234567890 + 90));
        $this->assertNull(Totp::verify($secret, '005924', $step, 1234567890));            // already used
        $this->assertNull(Totp::verify($secret, '12345', null, 1234567890));
    }

    public function test_two_step_sign_in(): void
    {
        // Every guest request from one address shares a rate limit; this test makes many in a row.
        $this->withoutMiddleware(ThrottleRequests::class);
        $twoFactor = app(TwoFactor::class);
        $this->actingAs($this->member);

        $this->get(route('account.edit'))->assertOk()->assertSee('İki adımlı giriş');
        $this->post(route('two-factor.start'), ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->post(route('two-factor.start'), ['current_password' => 'password'])->assertRedirect(route('two-factor.show'));

        $secret = $twoFactor->secret($this->member->fresh());
        $this->assertNotNull($secret);
        // The secret is stored encrypted and the QR code is drawn on the page.
        $this->assertStringNotContainsString($secret, (string) $this->member->fresh()->two_factor_secret);
        $this->get(route('two-factor.show'))->assertOk()->assertSee('<svg', false)->assertSee(substr($secret, 0, 4));
        $this->assertFalse($twoFactor->enabled($this->member->fresh()));

        $this->put(route('two-factor.confirm'), ['code' => '000000'])->assertSessionHasErrors('code');
        $response = $this->put(route('two-factor.confirm'), ['code' => Totp::codeAt($secret, Totp::step())])->assertRedirect();
        $codes = $response->getSession()->get('recovery_codes');
        $this->assertCount(8, $codes);
        $this->assertTrue($twoFactor->enabled($this->member->fresh()));

        // Signing in now stops after the password.
        $this->post(route('logout'));
        $this->post(route('login'), ['login' => $this->member->username, 'password' => 'password'])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->get(route('two-factor.challenge'))->assertOk();
        $this->post(route('two-factor.challenge'), ['code' => '123456'])->assertSessionHasErrors('code');
        $this->assertGuest();

        // The code that confirmed the setup cannot be replayed; the next one works.
        // (Codes are checked against the real clock, so the test names the steps instead of moving time.)
        $used = (int) $this->member->fresh()->two_factor_step;
        $this->post(route('two-factor.challenge'), ['code' => Totp::codeAt($secret, $used)])->assertSessionHasErrors('code');
        $this->post(route('two-factor.challenge'), ['code' => Totp::codeAt($secret, max($used, Totp::step()) + 1)])->assertRedirect(route('forum.index'));
        $this->assertAuthenticatedAs($this->member);

        // A recovery code works exactly once.
        $this->post(route('logout'));
        $this->post(route('login'), ['login' => $this->member->username, 'password' => 'password']);
        $this->post(route('two-factor.challenge'), ['code' => strtoupper($codes[0])])->assertRedirect(route('forum.index'));
        $this->assertSame(7, $twoFactor->recoveryCodesLeft($this->member->fresh()));
        $this->post(route('logout'));
        $this->post(route('login'), ['login' => $this->member->username, 'password' => 'password']);
        $this->post(route('two-factor.challenge'), ['code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest();

        // Without a pending sign-in the challenge page has nothing to do.
        $this->flushSession();
        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
        $this->post(route('two-factor.challenge'), ['code' => $codes[1]])->assertRedirect(route('login'));

        // An administrator can switch it off for somebody who lost the phone.
        $this->actingAs($this->admin)->put(route('admin.users.update', $this->member), [
            'username' => $this->member->username, 'email' => $this->member->email, 'user_group_id' => $this->member->user_group_id,
            'email_verified' => '1', 'reset_two_factor' => '1',
        ])->assertRedirect();
        $this->assertFalse($twoFactor->enabled($this->member->fresh()));
        $this->post(route('logout'));
        $this->post(route('login'), ['login' => $this->member->username, 'password' => 'password'])->assertRedirect(route('forum.index'));
    }

    public function test_sign_in_with_google(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('Google ile devam et');
        $this->get(route('social.redirect', 'google'))->assertNotFound();

        app(Settings::class)->set(['oauth.google_client_id' => 'client-123', 'oauth.google_client_secret' => 'secret-456']);
        $this->get(route('login'))->assertOk()->assertSee('Google ile devam et')->assertDontSee('GitHub ile devam et');

        $redirect = $this->get(route('social.redirect', 'google'))->assertRedirect();
        $location = $redirect->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('client-123', $query['client_id']);
        $this->assertSame(route('social.callback', 'google'), $query['redirect_uri']);
        $state = $query['state'];

        // What Google answers; changed between the visits below.
        $info = [];
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'token-1']),
            'openidconnect.googleapis.com/*' => function () use (&$info) {
                return Http::response($info);
            },
        ]);

        // A callback with the wrong state is refused before anything is asked from Google.
        $this->get(route('social.callback', ['provider' => 'google', 'state' => 'forged', 'code' => 'abc']))->assertRedirect(route('login'));
        Http::assertNothingSent();
        $this->assertGuest();

        $callback = function () {
            parse_str(parse_url($this->get(route('social.redirect', 'google'))->headers->get('Location'), PHP_URL_QUERY), $query);

            return $this->get(route('social.callback', ['provider' => 'google', 'state' => $query['state'], 'code' => 'abc']));
        };

        // An address Google has not verified is not trusted.
        $info = ['sub' => 'g-1', 'email' => 'yeni.uye@example.com', 'email_verified' => false, 'name' => 'Yeni Üye'];
        $callback()->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame(0, User::where('email', 'yeni.uye@example.com')->count());

        // First visit creates the account, already verified, with a usable username.
        $info = ['sub' => 'g-1', 'email' => 'yeni.uye@example.com', 'email_verified' => true, 'name' => 'Yeni Üye!'];
        $callback()->assertRedirect(route('forum.index'));
        $created = User::where('email', 'yeni.uye@example.com')->firstOrFail();
        $this->assertSame('Yeni_Üye', $created->username);
        $this->assertTrue($created->isVerified());
        $this->assertAuthenticatedAs($created);
        $this->assertSame($created->id, SocialAccount::where('provider', 'google')->where('provider_id', 'g-1')->value('user_id'));

        // The same Google account signs the same member in again, even after the e-mail address changed.
        $this->post(route('logout'));
        $created->update(['email' => 'degisti@example.com']);
        $callback()->assertRedirect(route('forum.index'));
        $this->assertAuthenticatedAs($created);
        $this->assertSame(1, User::whereIn('email', ['yeni.uye@example.com', 'degisti@example.com'])->count());

        // An existing member with the same address is linked, not duplicated.
        $this->post(route('logout'));
        $info = ['sub' => 'g-2', 'email' => $this->member->email, 'email_verified' => true, 'name' => 'x'];
        $callback()->assertRedirect(route('forum.index'));
        $this->assertAuthenticatedAs($this->member);
    }

    public function test_ip_and_email_bans_and_the_ip_log(): void
    {
        $this->post(route('login'), ['login' => $this->member->username, 'password' => 'password'])->assertRedirect();
        $this->assertDatabaseHas('ip_logs', ['user_id' => $this->member->id, 'ip' => '127.0.0.1', 'action' => 'login']);
        $this->post(route('logout'));

        $this->actingAs($this->member)->get(route('admin.bans'))->assertForbidden();
        $this->actingAs($this->admin);
        $this->get(route('admin.bans', ['ip' => '127.0.0.*']))->assertOk()->assertSee($this->member->username);
        $this->get(route('admin.users.edit', $this->member))->assertOk()->assertSee('127.0.0.1');

        // A rule that would hit the administrator is refused; odd characters are rejected.
        $this->post(route('admin.bans.save'), ['type' => 'ip', 'value' => '127.0.0.*'])->assertSessionHas('error');
        $this->post(route('admin.bans.save'), ['type' => 'ip', 'value' => '<script>'])->assertSessionHasErrors('value');
        $this->post(route('admin.bans.save'), ['type' => 'email', 'value' => '*@Spam.example', 'reason' => 'Spam'])->assertRedirect();
        $this->post(route('admin.bans.save'), ['type' => 'ip', 'value' => '203.0.113.*', 'days' => 2])->assertRedirect();
        $this->post(route('logout'));

        // E-mail ban stops registration.
        $form = ['username' => 'spamci', 'password' => 'gizli-sifre-123', 'password_confirmation' => 'gizli-sifre-123', 'terms' => '1'];
        $stamp = app(Guard::class)->stamp();
        $this->travel(5)->seconds();
        $this->post(route('register'), $form + ['email' => 'x@spam.example', 'form_stamp' => $stamp])->assertSessionHasErrors('email');
        $this->assertSame(0, User::where('username', 'spamci')->count());

        // IP ban: nothing but the sign-in page; administrators are never locked out.
        $banned = ['REMOTE_ADDR' => '203.0.113.9'];
        $this->get('/', $banned)->assertForbidden();
        $this->withServerVariables($banned)->get(route('login'))->assertOk();
        $this->withServerVariables($banned)->actingAs($this->member)->get('/')->assertForbidden();
        $this->withServerVariables($banned)->actingAs($this->admin)->get('/')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->actingAs($this->member)->get('/')->assertOk();

        // The ban runs out by itself.
        $this->travel(3)->days();
        $this->withServerVariables($banned)->actingAs($this->member)->get('/')->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->actingAs($this->admin)->delete(route('admin.bans.delete', Ban::where('type', 'email')->firstOrFail()))->assertRedirect();
        $this->assertSame(1, Ban::count());
    }

    public function test_ignoring_following_and_profile_posts(): void
    {
        $posting = app(Posting::class);
        $thread = $posting->createThread($this->forum, $this->member, 'Benim konum', 'Metin');
        $posting->reply($thread, $this->other, 'Görmek istemediğim cevap');
        $fromOther = fn () => $this->member->notifications()->where('data', 'like', '%"actor_id":'.$this->other->id.',%')->count();
        $this->assertSame(1, $fromOther());

        // Follow: counter and alert.
        $this->actingAs($this->member)->post(route('members.follow', $this->member))->assertStatus(422);
        $this->post(route('members.follow', $this->other))->assertRedirect();
        $this->assertSame(1, $this->other->fresh()->follower_count);
        $this->assertSame(1, $this->other->notifications()->where('data', 'like', '%"kind":"follow"%')->count());
        $this->get(route('members.show', $this->other))->assertOk()->assertSee('Takip ediliyor');

        // Ignore: the post is folded away, alerts and messages from that member stop, following ends.
        $this->post(route('members.ignore', $this->admin))->assertForbidden();
        $this->post(route('members.ignore', $this->other))->assertRedirect();
        $this->assertSame(0, $this->other->fresh()->follower_count);
        $this->get(route('threads.show', $thread))->assertOk()->assertSee('ignored-post', false)->assertSee('Bu üyeyi engellediniz.');
        $this->actingAs($this->admin)->get(route('threads.show', $thread))->assertOk()->assertDontSee('ignored-post', false);

        $posting->reply($thread, $this->other, 'Bir cevap daha @'.$this->member->username);
        $this->assertSame(1, $fromOther());
        $this->actingAs($this->other)->post(route('conversations.store'), ['recipients' => $this->member->username, 'title' => 'Selam', 'body' => 'Mesaj'])->assertSessionHasErrors('recipients');
        $this->post(route('members.follow', $this->member))->assertForbidden();
        $this->post(route('profile-posts.store', $this->member), ['body' => 'Profiline yazıyorum'])->assertForbidden();

        $this->actingAs($this->member)->get(route('account.edit'))->assertOk()->assertSee('Engellediğiniz üyeler')->assertSee($this->other->username);
        $this->post(route('members.ignore', $this->other))->assertRedirect();
        $this->assertSame([], app(Community::class)->ignoredIds($this->member));

        // Profile posts: written by members, removed by the author, the profile owner or a moderator.
        $this->actingAs($this->other)->post(route('profile-posts.store', $this->member), ['body' => "Merhaba!\n<b>kalın değil</b>"])->assertRedirect();
        $message = ProfilePost::firstOrFail();
        $this->assertSame(1, $this->member->notifications()->where('data', 'like', '%"kind":"profile_post"%')->count());
        $this->get(route('members.show', $this->member))->assertOk()->assertSee('Merhaba!')->assertSee('&lt;b&gt;kalın değil&lt;/b&gt;', false);

        $this->actingAs(User::factory()->create())->delete(route('profile-posts.destroy', $message))->assertForbidden();
        $this->actingAs($this->member)->delete(route('profile-posts.destroy', $message))->assertRedirect();
        $this->assertSame(0, ProfilePost::count());
    }

    public function test_profile_fields_titles_badges_and_promotions(): void
    {
        $this->actingAs($this->admin);
        $this->get(route('admin.profile'))->assertOk()->assertSee('Yeni Üye');
        $this->get(route('admin.rewards'))->assertOk()->assertSee('İlk Mesaj');

        // Profile fields
        $this->post(route('admin.profile.fields.save'), ['label' => 'Uzmanlık', 'type' => 'select', 'options' => 'SEO, Yazılım , Tasarım', 'is_active' => '1', 'is_required' => '1', 'show_on_posts' => '1'])->assertRedirect();
        $this->post(route('admin.profile.fields.save'), ['label' => 'LinkedIn', 'type' => 'url', 'is_active' => '1'])->assertRedirect();
        $skill = ProfileField::where('label', 'Uzmanlık')->firstOrFail();
        $link = ProfileField::where('label', 'LinkedIn')->firstOrFail();
        $this->assertSame(['SEO', 'Yazılım', 'Tasarım'], $skill->options);

        $this->actingAs($this->member);
        $this->get(route('account.edit'))->assertOk()->assertSee('Uzmanlık')->assertSee('LinkedIn');
        $this->put(route('account.profile'), ['fields' => [$link->id => 'https://example.com/in/ayse']])->assertSessionHasErrors('fields.'.$skill->id);
        $this->put(route('account.profile'), ['fields' => [$skill->id => 'Hackerlık']])->assertSessionHasErrors('fields.'.$skill->id);
        $this->put(route('account.profile'), ['fields' => [$skill->id => 'SEO', $link->id => 'javascript:alert(1)']])->assertSessionHasErrors('fields.'.$link->id);
        $this->put(route('account.profile'), ['fields' => [$skill->id => 'SEO', $link->id => 'https://example.com/in/ayse']])->assertRedirect();
        $this->get(route('members.show', $this->member))->assertOk()->assertSee('Uzmanlık')->assertSee('SEO')->assertSee('https://example.com/in/ayse', false);

        $thread = app(Posting::class)->createThread($this->forum, $this->member, 'Alanlı konu', 'Metin');
        $page = $this->get(route('threads.show', $thread))->assertOk()->assertSee('Uzmanlık');
        $this->assertStringNotContainsString('LinkedIn', $page->getContent());

        // Titles follow the post count, unless a custom title is set.
        $this->assertSame('Yeni Üye', $this->member->fresh()->displayTitle());
        $this->member->update(['post_count' => 120]);
        app(Rewards::class)->flush();
        $this->assertSame('Aktif Üye', $this->member->fresh()->displayTitle());
        $this->member->update(['custom_title' => 'Kurucu Ortak']);
        $this->assertSame('Kurucu Ortak', $this->member->fresh()->displayTitle());

        $this->actingAs($this->admin)->post(route('admin.profile.titles.save'), ['min_posts' => 100, 'title' => 'Çakışan'])->assertSessionHasErrors('min_posts');
        $this->post(route('admin.profile.titles.save'), ['min_posts' => 110, 'title' => 'Gayretli'])->assertRedirect();
        $this->member->update(['custom_title' => null]);
        $this->assertSame('Gayretli', $this->member->fresh()->displayTitle());

        // Badges: automatic ones are handed out by the task, once; manual ones by an administrator.
        $this->member->update(['reaction_score' => 60]);
        app(Tasks::class)->runDue(force: true);
        $titles = fn () => app(Rewards::class)->badgesOf($this->member)->pluck('title')->all();
        $this->assertSame(['İlk Mesaj', '100 Mesaj', 'Sevilen Üye'], $titles());
        $alerts = $this->member->notifications()->where('data', 'like', '%"kind":"badge"%')->count();
        $this->assertSame(3, $alerts);
        app(Tasks::class)->runDue(force: true);
        $this->assertSame(3, $this->member->notifications()->where('data', 'like', '%"kind":"badge"%')->count());
        $this->assertSame([], app(Rewards::class)->badgesOf($this->other)->all());

        $this->post(route('admin.rewards.badges.save'), ['title' => 'Etkinlik Konuşmacısı', 'icon' => '🎤', 'criteria' => 'manual', 'is_active' => '1', 'display_order' => 99])->assertRedirect();
        $manual = Badge::where('title', 'Etkinlik Konuşmacısı')->firstOrFail();
        $this->post(route('admin.users.badge', $this->other), ['badge_id' => $manual->id])->assertRedirect();
        $this->get(route('members.show', $this->other))->assertOk()->assertSee('Etkinlik Konuşmacısı');
        $this->post(route('admin.users.badge', $this->other), ['badge_id' => $manual->id])->assertRedirect();
        $this->assertSame(0, DB::table('user_badges')->where('user_id', $this->other->id)->count());

        // Promotions: every condition has to be met; system groups can never be the target.
        $this->post(route('admin.groups.store'), ['name' => 'Güvenilir Üye', 'perms' => ['mod.viewDeleted' => 'allow']])->assertRedirect();
        $trusted = UserGroup::where('name', 'Güvenilir Üye')->firstOrFail();
        $this->post(route('admin.rewards.promotions.save'), ['title' => 'Yönetici yap', 'user_group_id' => UserGroup::system('admin')->id, 'is_active' => '1'])->assertSessionHasErrors('user_group_id');
        $this->post(route('admin.rewards.promotions.save'), ['title' => 'Güvenilir', 'user_group_id' => $trusted->id, 'min_posts' => 100, 'min_days' => 30, 'min_reaction_score' => 50, 'is_active' => '1'])->assertRedirect();

        app(Tasks::class)->runDue(force: true);
        $this->assertFalse($this->member->fresh()->groups->contains($trusted->id)); // too new

        $this->travel(31)->days();
        app(Tasks::class)->runDue(force: true);
        $this->assertTrue($this->member->fresh()->groups->contains($trusted->id));
        $this->assertFalse($this->other->fresh()->groups->contains($trusted->id));
        $this->assertTrue(app(PermissionResolver::class)->can($this->member->fresh(), 'mod.viewDeleted'));
    }
}
