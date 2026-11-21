<?php

namespace Tests\Feature;

use App\Forum\Installer;
use App\Forum\Posting;
use App\Forum\Security\Totp;
use App\Forum\Security\TwoFactor;
use App\Forum\Settings;
use App\Models\ApiToken;
use App\Models\Attachment;
use App\Models\Node;
use App\Models\NodePermission;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The findings of the security review of November 2026, each kept closed by a test: guessing limits per account,
 * sessions and keys after a password change, accounts handed over by a sign-in provider, text message limits,
 * what is shown inline from uploads, the address the board answers under, and moderator tools across forums.
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $member;

    protected Node $forum;

    protected function setUp(): void
    {
        parent::setUp();

        app(Installer::class)->installDefaults();
        Storage::fake('local');
        $this->admin = User::factory()->inGroup('admin')->create();
        $this->member = User::factory()->create();
        $this->forum = Node::where('title', 'Genel Sohbet')->firstOrFail();
        app(Settings::class)->set(['posting.flood_seconds' => 0]);
        // The limits per address are not what is tested here; the limits per account are.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_password_guessing_is_limited_per_account_without_locking_the_owner_out(): void
    {
        $login = fn (string $password) => $this->post(route('login'), ['login' => $this->member->username, 'password' => $password]);

        for ($i = 0; $i < 10; $i++) {
            $login('yanlis-'.$i)->assertSessionHasErrors('login');
        }

        // From an address this account never used, even the right password has to wait now.
        $login('password')->assertSessionHasErrors('login');
        $this->assertGuest();
        $this->assertStringContainsString('dakika', session('errors')->first('login'));

        // The owner, at an address they signed in from before, is not held up by somebody else's guessing.
        DB::table('ip_logs')->insert(['user_id' => $this->member->id, 'ip' => '127.0.0.1', 'action' => 'login', 'created_at' => now()->subDays(3)]);
        $login('password')->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->member);
    }

    public function test_the_code_of_the_second_step_cannot_be_guessed_at_leisure(): void
    {
        $twoFactor = app(TwoFactor::class);
        $secret = $twoFactor->begin($this->member);
        $this->assertNotNull($twoFactor->confirm($this->member, Totp::codeAt($secret, Totp::step())));

        $this->post(route('login'), ['login' => $this->member->username, 'password' => 'password'])->assertRedirect(route('two-factor.challenge'));
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('two-factor.challenge'), ['code' => '00000'.$i])->assertSessionHasErrors('code');
        }

        // Five wrong codes: the account waits, whatever comes next and wherever it comes from.
        $this->post(route('two-factor.challenge'), ['code' => Totp::codeAt($secret, Totp::step() + 1)])->assertSessionHasErrors('code');
        $this->assertStringContainsString('dakika', session('errors')->first('code'));
        $this->assertGuest();

        // After the wait a fresh code is accepted again.
        $this->travel(16)->minutes();
        $this->post(route('login'), ['login' => $this->member->username, 'password' => 'password'])->assertRedirect(route('two-factor.challenge'));
        // (The step that confirmed the setup cannot be used twice, so the code of the next one is taken.)
        $this->post(route('two-factor.challenge'), ['code' => Totp::codeAt($secret, Totp::step() + 1)])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->member);
    }

    public function test_a_new_password_closes_what_was_open_on_the_account(): void
    {
        $open = function (User $user): void {
            DB::table('sessions')->where('user_id', $user->id)->delete();
            foreach (['bir', 'iki'] as $id) {
                DB::table('sessions')->insert(['id' => $id.'-'.$user->id, 'user_id' => $user->id, 'ip_address' => '10.0.0.9', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);
            }
            ApiToken::firstOrCreate(['user_id' => $user->id, 'name' => 'eski'], ['token_hash' => hash('sha256', 'k'.$user->id)]);
        };
        $sessions = fn (User $user) => DB::table('sessions')->where('user_id', $user->id)->count();

        // Changed by the member: every other browser is signed out; the keys the member made stay.
        $open($this->member);
        $this->actingAs($this->member)->put(route('account.security'), [
            'current_password' => 'password', 'email' => $this->member->email, 'password' => 'yeni-parola-123', 'password_confirmation' => 'yeni-parola-123',
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, $sessions($this->member));
        $this->assertSame(1, ApiToken::where('user_id', $this->member->id)->count());

        // Reset through the e-mail link: sessions and keys both end, since this is what one does after a break-in.
        $open($this->member);
        $this->post(route('logout'));
        $token = Password::createToken($this->member);
        $this->post(route('password.update'), ['token' => $token, 'email' => $this->member->email, 'password' => 'baska-parola-456', 'password_confirmation' => 'baska-parola-456'])
            ->assertRedirect(route('login'));
        $this->assertSame(0, $sessions($this->member));
        $this->assertSame(0, ApiToken::where('user_id', $this->member->id)->count());

        // Set by an administrator: the same.
        $open($this->member);
        $this->actingAs($this->admin)->put(route('admin.users.update', $this->member), [
            'username' => $this->member->username, 'email' => $this->member->email, 'user_group_id' => $this->member->user_group_id, 'password' => 'yonetici-verdi-789',
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, $sessions($this->member));
        $this->assertSame(0, ApiToken::where('user_id', $this->member->id)->count());
    }

    public function test_a_sign_in_provider_does_not_hand_over_an_account_whose_address_nobody_confirmed(): void
    {
        app(Settings::class)->set(['oauth.google_client_id' => 'client-123', 'oauth.google_client_secret' => 'secret-456']);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'token-1']),
            'openidconnect.googleapis.com/*' => Http::response(['sub' => 'g-77', 'email' => 'kurban@example.com', 'email_verified' => true, 'name' => 'Kurban']),
        ]);

        // Somebody registers with the victim's address (never confirmed), keeps a session, a key and the password.
        $planted = User::factory()->create(['email' => 'kurban@example.com', 'email_verified_at' => null, 'password' => 'saldirganin-parolasi']);
        DB::table('sessions')->insert(['id' => 'saldirgan', 'user_id' => $planted->id, 'ip_address' => '10.0.0.9', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);
        ApiToken::create(['user_id' => $planted->id, 'name' => 'arka kapi', 'token_hash' => hash('sha256', 'arka-kapi')]);

        // The real owner of the address signs in with Google.
        parse_str(parse_url($this->get(route('social.redirect', 'google'))->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->get(route('social.callback', ['provider' => 'google', 'state' => $query['state'], 'code' => 'abc']))->assertRedirect(route('forum.index'));
        $this->assertAuthenticatedAs($planted);
        $this->assertTrue($planted->fresh()->isVerified());

        // Whoever planted the account has no way in left.
        $this->assertSame(0, DB::table('sessions')->where('user_id', $planted->id)->count());
        $this->assertSame(0, ApiToken::where('user_id', $planted->id)->count());
        $this->post(route('logout'));
        $this->post(route('login'), ['login' => 'kurban@example.com', 'password' => 'saldirganin-parolasi'])->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_text_message_limits_do_not_start_again_when_the_number_is_removed(): void
    {
        app(Settings::class)->set(['sms.provider' => 'http', 'sms.http_url' => 'https://sms.example.com/send?to={phone}&text={message}', 'sms.http_success' => 'OK']);
        Http::fake(['sms.example.com/*' => Http::response('OK 1')]);
        $this->actingAs($this->member);

        for ($i = 0; $i < 4; $i++) {
            $this->post(route('account.phone.send'), ['phone' => '0532 123 45 67'])->assertSessionHasNoErrors();
            $this->delete(route('account.phone.remove'))->assertRedirect();
        }
        // The fifth of the day is refused although the pending row (and its counter) is gone.
        $this->post(route('account.phone.send'), ['phone' => '0532 123 45 67'])->assertSessionHasErrors('phone');
        // Another account cannot go on flooding the same number either.
        $this->actingAs(User::factory()->create())->post(route('account.phone.send'), ['phone' => '0532 123 45 67'])->assertSessionHasErrors('phone');
        Http::assertSentCount(4);

        // Where the board asks for confirmed addresses, an unconfirmed account sends no text messages at all.
        app(Settings::class)->set(['registration.verify_email' => true]);
        $this->actingAs(User::factory()->create(['email_verified_at' => null]))->post(route('account.phone.send'), ['phone' => '0533 765 43 21'])->assertSessionHasErrors('phone');
        Http::assertSentCount(4);
    }

    public function test_uploads_are_shown_inline_only_as_real_pictures_and_only_to_their_author(): void
    {
        $key = str_repeat('b', 32);
        $upload = fn (UploadedFile $file, string $k) => $this->post(route('attachments.store'), ['file' => $file, 'key' => $k, 'node_id' => $this->forum->id], ['Accept' => 'application/json']);
        $this->actingAs($this->member);

        // A format the image library reads but that is not one of ours (here: BMP; on newer PHP: SVG) is not a picture.
        $bmp = 'BM'.pack('VvvV', 58, 0, 0, 54).pack('VVVvvVVVVVV', 40, 1, 1, 1, 24, 0, 4, 2835, 2835, 0, 0)."\x00\x00\xff\x00";
        $this->assertNotFalse(getimagesizefromstring($bmp));
        $upload(UploadedFile::fake()->createWithContent('resim.png', $bmp), $key)->assertStatus(422);

        $upload(UploadedFile::fake()->image('foto.png', 900, 600), $key)->assertOk();
        $file = Attachment::firstOrFail();
        $this->assertSame('image/png', $file->mime);
        $shown = $this->get(route('attachments.show', $file))->assertOk();
        $this->assertSame('image/png', $shown->headers->get('Content-Type'));
        $this->assertStringContainsString('sandbox', (string) $shown->headers->get('Content-Security-Policy'));

        // A row that claims another type (stored by an older version) comes as a download, never inline.
        Attachment::whereKey($file->id)->update(['mime' => 'image/svg+xml']);
        $old = $this->get(route('attachments.show', $file))->assertOk();
        $this->assertSame('application/octet-stream', $old->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment', (string) $old->headers->get('Content-Disposition'));
        Attachment::whereKey($file->id)->update(['mime' => 'image/png']);

        // [attach] places the author's own files; the number of somebody else's file shows nothing about it.
        $preview = fn () => (string) $this->postJson(route('preview'), ['body' => '[attach]'.$file->id.'[/attach]'])->assertOk()->json('html');
        $this->assertStringContainsString('foto.png', $preview());
        $this->actingAs(User::factory()->create());
        $this->assertStringNotContainsString('foto', $preview());

        // What waits unposted is limited across all forms, so open drafts cannot fill the disk.
        $this->actingAs($hoarder = User::factory()->create());
        app(Settings::class)->set(['attachments.max_per_post' => 10]);
        $sent = 0;
        for ($form = 0; $form < 4 && $sent < 40; $form++) {
            for ($i = 0; $i < 10; $i++, $sent++) {
                $answer = $upload(UploadedFile::fake()->image("r$sent.png", 20, 20), str_pad((string) $form, 32, 'k'));
                if ($answer->status() === 422) {
                    break 2;
                }
            }
        }
        $this->assertSame(30, Attachment::where('user_id', $hoarder->id)->count());
    }

    public function test_the_board_answers_under_its_own_address_and_sends_protective_headers(): void
    {
        $page = $this->get('/')->assertOk();
        $this->assertSame('nosniff', $page->headers->get('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $page->headers->get('X-Frame-Options'));
        $this->assertStringContainsString("frame-ancestors 'self'", (string) $page->headers->get('Content-Security-Policy'));
        $this->assertNotNull($page->headers->get('Referrer-Policy'));

        // An installed board in production: a request under another host name is not served, it is sent to the real address.
        $env = storage_path('framework/testing/env-'.bin2hex(random_bytes(4)));
        file_put_contents($env, "APP_KEY=x\n");
        config(['forum.install.env_path' => $env, 'app.url' => 'https://forum.example.com']);
        $this->app['env'] = 'production';
        try {
            $this->get('http://evil.example/login?x=1')->assertRedirect('https://forum.example.com/login?x=1')->assertStatus(301);
            $this->post('http://evil.example/forgot-password', ['email' => $this->member->email])->assertStatus(400);
            $this->get('https://forum.example.com/login')->assertOk();
            // The switch for boards behind a proxy that rewrites the host name.
            config(['forum.force_host' => false]);
            $this->get('http://evil.example/login')->assertOk();
        } finally {
            $this->app['env'] = 'testing';
            @unlink($env);
            @unlink(dirname($env).'/installed');
        }

        // An alert only leads to an address of this board; a look-alike host that merely starts the same way does not count.
        $alert = fn (string $url) => DB::table('notifications')->insert([
            'id' => $id = (string) \Illuminate\Support\Str::uuid(), 'type' => 'x', 'notifiable_type' => $this->member->getMorphClass(), 'notifiable_id' => $this->member->id,
            'data' => json_encode(['kind' => 'reply', 'url' => $url]), 'created_at' => now(), 'updated_at' => now(),
        ]) ? $id : null;
        $this->actingAs($this->member);
        $this->get(route('notifications.open', $alert(url('/').'.evil.example/giris')))->assertRedirect(route('notifications.index'));
        $this->get(route('notifications.open', $alert(url('/threads/x'))))->assertRedirect(url('/threads/x'));
    }

    public function test_a_thread_is_moved_only_into_a_forum_where_the_moderator_may_move(): void
    {
        $moderator = User::factory()->inGroup('moderator')->create();
        $announcements = Node::where('title', 'Duyurular')->firstOrFail();
        NodePermission::create(['node_id' => $announcements->id, 'user_group_id' => UserGroup::system('moderator')->id, 'permissions' => ['mod.move' => 'never']]);
        $thread = app(Posting::class)->createThread($this->forum, $this->member, 'Taşınacak konu', 'İlk mesaj');

        $this->actingAs($moderator)->post(route('threads.moderate', $thread), ['action' => 'move', 'node_id' => $announcements->id])->assertForbidden();
        $this->assertSame($this->forum->id, $thread->fresh()->node_id);

        $other = Node::where('title', 'Tanışma')->firstOrFail();
        $this->post(route('threads.moderate', $thread), ['action' => 'move', 'node_id' => $other->id])->assertRedirect();
        $this->assertSame($other->id, $thread->fresh()->node_id);
    }
}
