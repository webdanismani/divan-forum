<?php

namespace Tests\Feature;

use App\Forum\Import\Importer;
use App\Forum\Import\Markup;
use App\Forum\Installer;
use App\Forum\Integrations\Push;
use App\Forum\Integrations\Webhooks;
use App\Forum\Posting;
use App\Forum\Settings;
use App\Models\ApiToken;
use App\Models\ImportRun;
use App\Models\Node;
use App\Models\NodePermission;
use App\Models\Post;
use App\Models\PushSubscription;
use App\Models\Thread;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Webhook;
use App\Notifications\ForumAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** REST API, webhooks, the installable app with browser notifications, and imports from other forum software. */
class PhaseFiveIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $member;

    protected Node $forum;

    protected array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        app(Installer::class)->installDefaults();
        $this->admin = User::factory()->inGroup('admin')->create();
        $this->member = User::factory()->create();
        $this->forum = Node::where('title', 'Genel Sohbet')->firstOrFail();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    protected function tearDown(): void
    {
        DB::purge('import');
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    // ---- API -----------------------------------------------------------------------------------

    public function test_the_api_is_off_until_it_is_switched_on(): void
    {
        $this->getJson('/api/v1/forums')->assertNotFound();
        $this->actingAs($this->member)->post(route('account.api-keys.store'), ['name' => 'x'])->assertNotFound();
        $this->get(route('account.edit'))->assertOk()->assertDontSee('API anahtarları');
    }

    public function test_api_keys_read_and_write_with_the_owners_permissions(): void
    {
        app(Settings::class)->set(['api.enabled' => true, 'posting.flood_seconds' => 0]);

        $private = Node::create(['type' => 'forum', 'title' => 'Ekip Odası', 'parent_id' => $this->forum->parent_id]);
        foreach (['guest', 'registered'] as $key) {
            NodePermission::create(['node_id' => $private->id, 'user_group_id' => UserGroup::system($key)->id, 'permissions' => ['node.view' => 'never']]);
        }
        $secret = app(Posting::class)->createThread($private, $this->admin, 'Gizli konu', 'Kimse görmesin');

        // A guest sees what a guest sees on the site.
        $titles = collect($this->getJson('/api/v1/forums')->assertOk()->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Genel Sohbet'));
        $this->assertFalse($titles->contains('Ekip Odası'));
        $this->getJson("/api/v1/threads/{$secret->id}")->assertUnauthorized();
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->postJson("/api/v1/forums/{$this->forum->id}/threads", ['title' => 'Misafir', 'body' => 'Olmaz'])->assertUnauthorized();

        // The key is shown once and only its hash is stored.
        $this->actingAs($this->member)->get(route('account.edit'))->assertOk()->assertSee('API anahtarları');
        // A key outlives sessions and password changes: making one asks for the password.
        $this->post(route('account.api-keys.store'), ['name' => 'Otomasyon'])->assertSessionHasErrors('current_password');
        $this->post(route('account.api-keys.store'), ['name' => 'Otomasyon', 'current_password' => 'yanlis-parola'])->assertSessionHasErrors('current_password');
        $this->assertSame(0, ApiToken::count());
        $this->post(route('account.api-keys.store'), ['name' => 'Otomasyon', 'current_password' => 'password'])->assertSessionHas('api_key');
        $key = session('api_key');
        $this->assertStringStartsWith('mf_', $key);
        $this->assertSame(hash('sha256', $key), ApiToken::firstOrFail()->token_hash);
        $this->post(route('logout'));

        $headers = ['Authorization' => 'Bearer '.$key];
        $this->getJson('/api/v1/me', $headers)->assertOk()->assertJsonPath('data.username', $this->member->username)->assertJsonPath('data.email', $this->member->email);
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer mf_wrong'])->assertUnauthorized();

        $created = $this->postJson("/api/v1/forums/{$this->forum->id}/threads", ['title' => 'API ile açıldı', 'body' => '[b]Merhaba[/b] dünya', 'tags' => 'api, deneme'], $headers)
            ->assertCreated()->assertJsonPath('data.title', 'API ile açıldı')->assertJsonPath('data.state', 'visible');
        $threadId = $created->json('data.id');
        $this->assertSame($this->member->id, Thread::findOrFail($threadId)->user_id);
        $this->assertSame(2, Thread::findOrFail($threadId)->tags()->count());

        $this->postJson("/api/v1/threads/{$threadId}/posts", ['body' => 'API yanıtı'], $headers)->assertCreated()->assertJsonPath('data.thread_id', $threadId);
        $this->postJson("/api/v1/threads/{$threadId}/posts", ['body' => ''], $headers)->assertUnprocessable();

        $this->getJson("/api/v1/threads/{$threadId}", $headers)->assertOk()->assertJsonPath('data.reply_count', 1)->assertJsonPath('data.first_post.body_html', '<strong>Merhaba</strong> dünya');
        $this->getJson("/api/v1/threads/{$threadId}/posts", $headers)->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.1.text', 'API yanıtı');
        $this->getJson("/api/v1/forums/{$this->forum->id}/threads", $headers)->assertOk()->assertJsonPath('data.0.id', $threadId);
        $this->getJson("/api/v1/members/{$this->admin->id}", $headers)->assertOk()->assertJsonPath('data.username', $this->admin->username)->assertJsonMissingPath('data.email');

        // The member's key cannot reach what the member cannot see, and never writes there.
        $this->getJson("/api/v1/threads/{$secret->id}", $headers)->assertForbidden();
        $this->getJson("/api/v1/forums/{$private->id}/threads", $headers)->assertForbidden();
        $this->postJson("/api/v1/threads/{$secret->id}/posts", ['body' => 'Sızma'], $headers)->assertForbidden();
        $this->assertSame(1, Post::where('thread_id', $secret->id)->count());

        // A banned member's key stops working; a deleted key too.
        $this->member->update(['is_banned' => true]);
        $this->getJson('/api/v1/me', $headers)->assertUnauthorized();
        $this->member->update(['is_banned' => false]);
        $this->actingAs($this->member)->delete(route('account.api-keys.destroy', ApiToken::firstOrFail()->id))->assertRedirect();
        $this->post(route('logout'));
        $this->getJson('/api/v1/me', $headers)->assertUnauthorized();
    }

    // ---- Webhooks ------------------------------------------------------------------------------

    public function test_webhooks_are_signed_and_sent_for_the_chosen_events(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('ok', 200), 'down.example.com/*' => Http::response('boom', 500)]);

        $this->actingAs($this->member)->post(route('admin.integrations.webhooks.save'), ['url' => 'https://hooks.example.com/a', 'events' => ['thread.created']])->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.integrations'))->assertOk()->assertSee('Yeni webhook');
        $this->post(route('admin.integrations.webhooks.save'), ['url' => 'https://hooks.example.com/in', 'events' => ['thread.created', 'user.registered'], 'is_active' => 1])->assertRedirect();
        $this->post(route('admin.integrations.webhooks.save'), ['url' => 'javascript:alert(1)', 'events' => ['thread.created']])->assertSessionHasErrors('url');
        $hook = Webhook::firstOrFail();
        $this->assertSame(40, strlen($hook->secret));

        $thread = app(Posting::class)->createThread($this->forum, $this->member, 'Webhook konusu', 'İlk mesaj [b]kalın[/b]');
        app(Posting::class)->reply($thread, $this->admin, 'Bu olay istenmedi');

        Http::assertSentCount(1);
        Http::assertSent(function (ClientRequest $request) use ($hook, $thread) {
            $data = json_decode($request->body(), true);

            return $request->url() === 'https://hooks.example.com/in'
                && $request->header('X-Forum-Event')[0] === 'thread.created'
                && hash_equals(Webhooks::sign($request->body(), $hook->secret), $request->header('X-Forum-Signature')[0])
                && $data['data']['id'] === $thread->id
                && $data['data']['text'] === 'İlk mesaj kalın'
                && $data['data']['author']['username'] === $this->member->username
                && ! str_contains($request->body(), $this->member->email);
        });
        $this->assertSame(200, $hook->refresh()->last_status);

        // A thread that waits for approval is nobody's business yet.
        $this->forum->update(['require_approval' => true]);
        app(Posting::class)->createThread($this->forum, $this->member, 'Onay bekliyor', 'Metin');
        Http::assertSentCount(1);

        // The test button reports what the other side answered.
        $this->post(route('admin.integrations.webhooks.test', $hook))->assertSessionHas('success');
        $hook->update(['url' => 'https://down.example.com/x', 'is_active' => false]);
        $this->post(route('admin.integrations.webhooks.test', $hook))->assertSessionHas('error');
        $this->assertSame(500, $hook->refresh()->last_status);

        $this->delete(route('admin.integrations.webhooks.delete', $hook))->assertRedirect();
        $this->assertSame(0, Webhook::count());
    }

    // ---- App and browser notifications ------------------------------------------------------------

    public function test_the_forum_can_be_installed_as_an_app(): void
    {
        $this->get('/')->assertOk()->assertSee('rel="manifest"', false)->assertSee('name="service-worker"', false);

        $manifest = $this->get('/manifest.webmanifest')->assertOk()->assertHeader('Content-Type', 'application/manifest+json')->json();
        $this->assertSame(setting('board.name'), $manifest['name']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertCount(2, $manifest['icons']);

        $icon = $this->get($manifest['icons'][0]['src'])->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame([192, 192], array_slice(getimagesize($icon->baseResponse->getFile()->getPathname()), 0, 2));
        $this->get('/app-icon-999.png')->assertNotFound();

        $worker = $this->get('/sw.js')->assertOk()->getContent();
        $this->assertStringContainsString("addEventListener('push'", $worker);
        $this->assertStringContainsString('"offline":"/offline"', $worker);
        $this->get('/offline')->assertOk()->assertSee('Bağlantı yok');

        app(Settings::class)->set(['pwa.enabled' => false]);
        $this->get('/manifest.webmanifest')->assertNotFound();
        $this->get('/sw.js')->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee('rel="manifest"', false);
    }

    public function test_alerts_wake_subscribed_browsers(): void
    {
        $push = app(Push::class);
        $public = $push->publicKey();
        $this->assertNotNull($public, 'OpenSSL could not create an EC key pair.');
        $this->assertSame(65, strlen(base64_decode(strtr($public, '-_', '+/'))));
        $this->assertSame($public, app(Push::class)->publicKey(), 'The key pair is created once and then kept.');

        // The token is a real ES256 signature over header.claims.
        $private = Crypt::decryptString(setting('push.vapid_private'));
        [$head, $claims, $signature] = explode('.', $push->jwt('https://fcm.googleapis.com', $private));
        $raw = base64_decode(strtr($signature, '-_', '+/'));
        $this->assertSame(64, strlen($raw));
        $der = function (string $int) {
            $int = ltrim($int, "\0");
            $int = ($int === '' || ord($int[0]) & 0x80) ? "\0".$int : $int;

            return "\x02".chr(strlen($int)).$int;
        };
        $sequence = $der(substr($raw, 0, 32)).$der(substr($raw, 32));
        $this->assertSame(1, openssl_verify("$head.$claims", "\x30".chr(strlen($sequence)).$sequence, openssl_pkey_get_details(openssl_pkey_get_private($private))['key'], OPENSSL_ALGO_SHA256));
        $this->assertSame('https://fcm.googleapis.com', json_decode(base64_decode(strtr($claims, '-_', '+/')), true)['aud']);

        $this->actingAs($this->member)->get(route('account.edit'))->assertOk()->assertSee('Tarayıcı bildirimleri')->assertSee($public);

        // Only the browser makers' push services are accepted: the server posts to this address.
        foreach (['https://evil.example.com/push', 'http://fcm.googleapis.com/fcm/send/x', 'https://fcm.googleapis.com.evil.com/x', 'https://127.0.0.1/x'] as $bad) {
            $this->postJson(route('push.subscribe'), ['endpoint' => $bad])->assertStatus(422);
        }
        $this->postJson(route('push.subscribe'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc'])->assertOk();
        $this->postJson(route('push.subscribe'), ['endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/gone'])->assertOk();
        $this->assertSame(2, PushSubscription::where('user_id', $this->member->id)->count());

        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201), 'updates.push.services.mozilla.com/*' => Http::response('', 410)]);
        $this->member->notify(new ForumAlert('follow', $this->admin, route('members.show', $this->admin)));

        Http::assertSentCount(2);
        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://fcm.googleapis.com/fcm/send/abc'
            && str_starts_with($request->header('Authorization')[0], 'vapid t=')
            && str_ends_with($request->header('Authorization')[0], ', k='.$public)
            && $request->header('TTL')[0] === '86400'
            && $request->body() === '');
        // The browser that answered "gone" is forgotten.
        $this->assertSame(['https://fcm.googleapis.com/fcm/send/abc'], PushSubscription::pluck('endpoint')->all());

        // What the service worker shows: the member's newest alert, or a neutral line for a signed-out browser.
        $latest = $this->getJson(route('push.latest'))->assertOk()->json();
        $this->assertStringContainsString($this->admin->username, $latest['body']);
        $this->assertStringContainsString('/alerts/', $latest['url']);

        $this->deleteJson(route('push.unsubscribe'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc'])->assertOk();
        $this->assertSame(0, PushSubscription::count());
        $this->post(route('logout'));
        $this->assertSame('Yeni bir bildiriminiz var.', $this->getJson(route('push.latest'))->assertOk()->json('body'));

        // Switched off: nothing is sent.
        PushSubscription::create(['user_id' => $this->member->id, 'endpoint_hash' => hash('sha256', 'x'), 'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc']);
        app(Settings::class)->set(['push.enabled' => false]);
        $this->member->notify(new ForumAlert('follow', $this->admin, route('members.show', $this->admin)));
        Http::assertSentCount(2);
    }

    // ---- Import --------------------------------------------------------------------------------

    public function test_markup_of_other_packages_becomes_this_forums_bbcode(): void
    {
        $this->assertSame('[quote=Ali]Selam[/quote]', Markup::convert('[QUOTE="Ali, post: 12, member: 3"]Selam[/QUOTE]'));
        $this->assertSame('[quote=Veli]Selam[/quote]', Markup::convert('[QUOTE=Veli;4521]Selam[/QUOTE]'));
        $this->assertSame('[quote=Can]Selam[/quote]', Markup::convert("[quote='Can' pid='7' dateline='1500000000']Selam[/quote]"));
        $this->assertSame('[quote=Ece]Selam[/quote]', Markup::convert('[quote author=Ece link=topic=5.msg9#msg9 date=1500000000]Selam[/quote]'));
        $this->assertSame("önce\nhttps://www.youtube.com/watch?v=dQw4w9WgXcQ\nsonra", Markup::convert('önce[MEDIA=youtube]dQw4w9WgXcQ[/MEDIA]sonra'));
        $this->assertSame('[url=https://example.com]site[/url] [s]eski[/s] metin', Markup::convert('[URL="https://example.com"]site[/URL] [STRIKE]eski[/STRIKE] [FONT=Arial][SIZE=14px]metin[/SIZE][/FONT]'));
        $this->assertSame('dosya:', Markup::convert('dosya: [ATTACH=full]55[/ATTACH]'));
        $this->assertSame("satır 1\nsatır 2 & \"üç\"", Markup::convert('satır 1<br />satır 2 &amp; &quot;üç&quot;', true));
        // Windows-1254 bytes from an old latin1 database arrive as proper Turkish.
        $this->assertSame('şöğüçı İĞ', Importer::text(mb_convert_encoding('şöğüçı İĞ', 'Windows-1254', 'UTF-8')));
    }

    /** @return array<string, array{0: string}> */
    public static function packages(): array
    {
        return ['XenForo' => ['xenforo'], 'vBulletin' => ['vbulletin'], 'MyBB' => ['mybb'], 'SMF' => ['smf']];
    }

    #[DataProvider('packages')]
    public function test_another_forum_is_imported(string $package): void
    {
        $config = $this->oldForum($package);
        $existing = User::factory()->create(['email' => 'zaten@example.com']);
        $before = ['users' => User::count(), 'nodes' => Node::count()];

        $importer = app(Importer::class);
        $run = $importer->start($package, $config);
        $this->assertSame(['users' => 3, 'forums' => 2, 'threads' => 2, 'posts' => 3], $run->stats['totals']);
        $this->assertStringNotContainsString('old-secret', $run->getRawOriginal('config'));

        for ($i = 0; $i < 40 && $run->status === 'running'; $i++) {
            $run = $importer->advance($run, 2);
        }

        $this->assertSame('done', $run->status, (string) $run->error);
        $this->assertSame('', $run->config, 'The connection details are thrown away when the import has finished.');
        $this->assertSame(100, $importer->percent($run));

        // Two new members; the third already had an account here and was joined with it.
        $this->assertSame($before['users'] + 2, User::count());
        $this->assertSame(1, $run->stats['merged']);
        $ayse = User::where('email', 'ayse@example.com')->firstOrFail();
        $this->assertSame('Ayşe', $ayse->username);
        $this->assertSame(UserGroup::system('registered')->id, $ayse->user_group_id);
        $this->assertNotNull($ayse->email_verified_at);
        $this->assertSame('2020-01-01', $ayse->created_at->toDateString());
        $this->assertTrue((bool) User::where('email', 'yasakli@example.com')->firstOrFail()->is_banned);

        // Category and forum keep their hierarchy.
        $this->assertSame($before['nodes'] + 2, Node::count());
        $category = Node::where('title', 'Eski Kategori')->firstOrFail();
        $forum = Node::where('title', 'Eski Forum & Sohbet')->firstOrFail();
        $this->assertSame('category', $category->type);
        $this->assertSame($category->id, $forum->parent_id);

        $thread = Thread::where('title', 'İlk konu')->firstOrFail();
        $this->assertSame($forum->id, $thread->node_id);
        $this->assertSame($ayse->id, $thread->user_id);
        $this->assertSame(1, $thread->reply_count);
        $this->assertTrue((bool) $thread->is_sticky);
        $this->assertSame(250, $thread->view_count);
        $this->assertSame('2020-01-02', $thread->created_at->toDateString());

        $posts = $thread->posts()->orderBy('id')->get();
        $this->assertCount(2, $posts);
        $this->assertSame($thread->first_post_id, $posts[0]->id);
        $this->assertSame($thread->last_post_id, $posts[1]->id);
        $this->assertSame('[b]Merhaba[/b] dünya', $posts[0]->body);
        $this->assertSame('<strong>Merhaba</strong> dünya', $posts[0]->body_html);
        $this->assertSame($existing->id, $posts[1]->user_id, 'The reply belongs to the account that already existed.');
        $this->assertStringContainsString('<blockquote class="bb-quote">', $posts[1]->body_html);
        $this->assertSame('moderated', Thread::where('title', 'Onay bekleyen')->firstOrFail()->state);

        // Counters were rebuilt.
        $this->assertSame(1, $ayse->refresh()->thread_count);
        $this->assertSame(1, $existing->refresh()->post_count);
        $this->assertSame(1, $forum->refresh()->thread_count);
        $this->assertSame(2, $forum->post_count);

        // The thread opens like any other, and the member signs in with the password of the old forum.
        $this->get(route('threads.show', $thread))->assertOk()->assertSee('İlk konu');
        $this->post(route('login'), ['login' => 'ayse@example.com', 'password' => 'yanlış'])->assertSessionHasErrors('login');
        $this->post(route('login'), ['login' => 'ayse@example.com', 'password' => 'eski-parola'])->assertRedirect(route('forum.index'));
        $this->assertAuthenticatedAs($ayse);
        $this->assertNull($ayse->refresh()->legacy_password);
        $this->assertTrue(password_verify('eski-parola', $ayse->password));
    }

    public function test_the_import_page_reports_progress_and_bad_connections(): void
    {
        $this->actingAs($this->member)->get(route('admin.import'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.import'))->assertOk()->assertSee('XenForo 2')->assertSee('MyBB 1.8');

        $this->post(route('admin.import.start'), ['source' => 'mybb', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'nothing', 'username' => 'nobody', 'password' => 'x', 'prefix' => 'mybb_', 'charset' => 'utf8mb4'])
            ->assertRedirect()->assertSessionHas('error');
        $this->post(route('admin.import.start'), ['source' => 'mybb', 'host' => 'h', 'port' => 3306, 'database' => 'd', 'username' => 'u', 'prefix' => 'a b;--', 'charset' => 'utf8mb4'])->assertSessionHasErrors('prefix');
        $this->assertSame(0, ImportRun::count());

        $run = app(Importer::class)->start('mybb', $this->oldForum('mybb'));
        $this->get(route('admin.import'))->assertOk()->assertSee('data-import=', false)->assertDontSee('Yeni içe aktarım');

        $answer = $this->postJson(route('admin.import.advance', $run))->assertOk()->json();
        $this->assertSame('running', $answer['status']);
        $this->assertSame(3, $answer['done']['users']);

        // Stopping keeps what was imported and forgets the connection details.
        $this->put(route('admin.import.update', $run), ['action' => 'stop'])->assertRedirect();
        $this->assertSame('failed', $run->refresh()->status);
        $this->assertSame('', $run->config);
        $this->postJson(route('admin.import.advance', $run))->assertOk()->assertJsonPath('status', 'failed');
        $this->get(route('admin.import'))->assertOk()->assertSee('Yeni içe aktarım');
    }

    /** A tiny copy of the package's database: 3 members, a category with a forum, 2 threads, 3 posts. */
    protected function oldForum(string $package): array
    {
        $this->files[] = $file = storage_path('framework/testing/import-'.$package.'-'.bin2hex(random_bytes(4)).'.sqlite');
        @mkdir(dirname($file), 0777, true);
        touch($file);

        $prefix = ['xenforo' => 'xf_', 'vbulletin' => 'vb_', 'mybb' => 'mybb_', 'smf' => 'smf_'][$package];
        $config = ['driver' => 'sqlite', 'database' => $file, 'prefix' => $prefix, 'password' => 'old-secret'];
        $db = app(Importer::class)->connect($config);
        $schema = Schema::connection('import');

        $table = function (string $name, array $rows) use ($db, $schema) {
            $schema->create($name, function ($table) use ($rows) {
                foreach (array_keys($rows[0]) as $column) {
                    is_int($rows[0][$column]) ? $table->bigInteger($column)->nullable() : $table->text($column)->nullable();
                }
            });
            $db->table($name)->insert($rows);
        };

        $t = ['ayse' => strtotime('2020-01-01 12:00:00'), 'thread' => strtotime('2020-01-02 12:00:00'), 'reply' => strtotime('2020-01-03 12:00:00')];
        $first = '[B]Merhaba[/B] dünya';

        if ($package === 'xenforo') {
            $user = fn (int $id, string $name, string $email, int $banned = 0) => ['user_id' => $id, 'username' => $name, 'email' => $email, 'register_date' => $t['ayse'], 'last_activity' => $t['reply'], 'custom_title' => '', 'is_banned' => $banned];
            $table('user', [$user(1, 'Ayşe', 'ayse@example.com'), $user(2, 'Zaten', 'ZATEN@example.com'), $user(3, 'Yasaklı', 'yasakli@example.com', 1)]);
            $table('user_authenticate', [['user_id' => 1, 'scheme_class' => 'XF:Core12', 'data' => serialize(['hash' => password_hash('eski-parola', PASSWORD_BCRYPT, ['cost' => 4])])]]);
            $table('user_profile', [['user_id' => 1, 'location' => 'İzmir', 'website' => 'https://example.com', 'signature' => 'İmza']]);
            $node = fn (int $id, string $title, string $type, int $parent, int $lft) => ['node_id' => $id, 'title' => $title, 'description' => '', 'node_type_id' => $type, 'parent_node_id' => $parent, 'display_order' => $lft, 'lft' => $lft];
            $table('node', [$node(5, 'Eski Kategori', 'Category', 0, 1), $node(6, 'Eski Forum & Sohbet', 'Forum', 5, 2), $node(7, 'Bağlantı', 'LinkForum', 5, 3)]);
            $thread = fn (int $id, string $title, string $state, string $type = 'discussion') => ['thread_id' => $id, 'node_id' => 6, 'title' => $title, 'user_id' => 1, 'post_date' => $t['thread'], 'view_count' => 250, 'sticky' => $id === 10 ? 1 : 0, 'discussion_open' => 1, 'discussion_state' => $state, 'discussion_type' => $type];
            $table('thread', [$thread(10, 'İlk konu', 'visible'), $thread(11, 'Onay bekleyen', 'moderated'), $thread(12, 'Taşındı', 'visible', 'redirect')]);
            $post = fn (int $id, int $thread, int $user, string $body, int $time) => ['post_id' => $id, 'thread_id' => $thread, 'user_id' => $user, 'post_date' => $time, 'message' => $body, 'message_state' => 'visible'];
            $table('post', [$post(100, 10, 1, $first, $t['thread']), $post(101, 10, 2, '[QUOTE="Ayşe, post: 100, member: 1"]Merhaba[/QUOTE] Selam', $t['reply']), $post(102, 11, 1, 'Bekleyen', $t['reply'])]);
        }

        if ($package === 'vbulletin') {
            $user = fn (int $id, string $name, string $email, int $group = 2) => ['userid' => $id, 'username' => $name, 'email' => $email, 'password' => md5(md5('eski-parola').'tuz'), 'salt' => 'tuz', 'joindate' => $t['ayse'], 'lastactivity' => $t['reply'], 'usertitle' => 'Üye', 'customtitle' => 0, 'homepage' => '', 'usergroupid' => $group];
            $table('user', [$user(1, 'Ayşe', 'ayse@example.com'), $user(2, 'Zaten', 'zaten@example.com'), $user(3, 'Yasaklı', 'yasakli@example.com', 8)]);
            $table('usertextfield', [['userid' => 1, 'signature' => 'İmza']]);
            $forum = fn (int $id, string $title, int $parent, int $options) => ['forumid' => $id, 'title' => $title, 'description' => '', 'parentid' => $parent, 'displayorder' => $id, 'options' => $options];
            $table('forum', [$forum(5, 'Eski Kategori', -1, 1), $forum(6, 'Eski Forum &amp; Sohbet', 5, 7)]);
            $thread = fn (int $id, string $title, int $visible, int $open = 1) => ['threadid' => $id, 'forumid' => 6, 'title' => $title, 'postuserid' => 1, 'dateline' => $t['thread'], 'views' => 250, 'sticky' => $id === 10 ? 1 : 0, 'open' => $open, 'visible' => $visible];
            $table('thread', [$thread(10, 'İlk konu', 1), $thread(11, 'Onay bekleyen', 0), $thread(12, 'Taşındı', 1, 10)]);
            $post = fn (int $id, int $thread, int $user, string $body, int $time) => ['postid' => $id, 'threadid' => $thread, 'userid' => $user, 'dateline' => $time, 'pagetext' => $body, 'ipaddress' => '10.0.0.5', 'visible' => 1];
            $table('post', [$post(100, 10, 1, $first, $t['thread']), $post(101, 10, 2, '[QUOTE=Ayşe;100]Merhaba[/QUOTE] Selam', $t['reply']), $post(102, 11, 1, 'Bekleyen', $t['reply'])]);
        }

        if ($package === 'mybb') {
            $user = fn (int $id, string $name, string $email, int $group = 2) => ['uid' => $id, 'username' => $name, 'email' => $email, 'password' => md5(md5('tuz').md5('eski-parola')), 'salt' => 'tuz', 'regdate' => $t['ayse'], 'lastactive' => $t['reply'], 'usertitle' => '', 'website' => '', 'signature' => '', 'usergroup' => $group];
            $table('users', [$user(1, 'Ayşe', 'ayse@example.com'), $user(2, 'Zaten', 'zaten@example.com'), $user(3, 'Yasaklı', 'yasakli@example.com', 7)]);
            $forum = fn (int $id, string $name, string $type, int $parent) => ['fid' => $id, 'name' => $name, 'description' => '', 'type' => $type, 'pid' => $parent, 'disporder' => $id];
            $table('forums', [$forum(5, 'Eski Kategori', 'c', 0), $forum(6, 'Eski Forum &amp; Sohbet', 'f', 5)]);
            $thread = fn (int $id, string $title, int $visible, string $closed = '') => ['tid' => $id, 'fid' => 6, 'subject' => $title, 'uid' => 1, 'dateline' => $t['thread'], 'views' => 250, 'sticky' => $id === 10 ? 1 : 0, 'closed' => $closed, 'visible' => $visible];
            $table('threads', [$thread(10, 'İlk konu', 1), $thread(11, 'Onay bekleyen', 0), $thread(12, 'Taşındı', 1, 'moved|10')]);
            $post = fn (int $id, int $thread, int $user, string $body, int $time) => ['pid' => $id, 'tid' => $thread, 'uid' => $user, 'dateline' => $time, 'message' => $body, 'ipaddress' => inet_pton('10.0.0.5'), 'visible' => 1];
            $table('posts', [$post(100, 10, 1, $first, $t['thread']), $post(101, 10, 2, "[quote='Ayşe' pid='100' dateline='1577966400']Merhaba[/quote] Selam", $t['reply']), $post(102, 11, 1, 'Bekleyen', $t['reply'])]);
        }

        if ($package === 'smf') {
            // The login name is lower-cased before hashing; SMF 2.0 uses SHA-1.
            $user = fn (int $id, string $name, string $email, int $activated = 1) => ['id_member' => $id, 'member_name' => $name, 'real_name' => $name, 'email_address' => $email, 'passwd' => sha1(mb_strtolower($name).'eski-parola'), 'date_registered' => $t['ayse'], 'last_login' => $t['reply'], 'usertitle' => '', 'location' => '', 'website_url' => '', 'signature' => '', 'is_activated' => $activated];
            $table('members', [$user(1, 'Ayşe', 'ayse@example.com'), $user(2, 'Zaten', 'zaten@example.com'), $user(3, 'Yasaklı', 'yasakli@example.com', 11)]);
            $table('categories', [['id_cat' => 6, 'name' => 'Eski Kategori', 'cat_order' => 1]]);
            $table('boards', [['id_board' => 6, 'id_cat' => 6, 'id_parent' => 0, 'name' => 'Eski Forum &amp; Sohbet', 'description' => '', 'board_order' => 1]]);
            $topic = fn (int $id, int $first, int $approved) => ['id_topic' => $id, 'id_board' => 6, 'id_first_msg' => $first, 'id_member_started' => 1, 'num_views' => 250, 'is_sticky' => $id === 10 ? 1 : 0, 'locked' => 0, 'approved' => $approved];
            $table('topics', [$topic(10, 100, 1), $topic(11, 102, 0)]);
            $message = fn (int $id, int $topic, int $user, string $subject, string $body, int $time) => ['id_msg' => $id, 'id_topic' => $topic, 'id_member' => $user, 'poster_time' => $time, 'subject' => $subject, 'body' => $body, 'poster_ip' => '10.0.0.5', 'approved' => 1];
            $table('messages', [
                $message(100, 10, 1, 'İlk konu', '[b]Merhaba[/b] d&uuml;nya', $t['thread']),
                $message(101, 10, 2, 'Ynt: İlk konu', '[quote author=Ayşe link=topic=10.msg100#msg100 date=1577966400]Merhaba[/quote]<br />Selam', $t['reply']),
                $message(102, 11, 1, 'Onay bekleyen', 'Bekleyen', $t['reply']),
            ]);
        }

        return $config;
    }
}
