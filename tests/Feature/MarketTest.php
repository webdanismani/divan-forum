<?php

namespace Tests\Feature;

use App\Forum\Addons\Manager;
use App\Forum\Installer;
use App\Forum\Settings;
use App\Forum\Sms;
use App\Forum\Store\Client;
use App\Forum\Theme;
use App\Forum\Themes;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesLicenseServer;
use Tests\TestCase;
use ZipArchive;

/** The admin market (remote modules and themes), installable themes, and the NetGSM text message provider. */
class MarketTest extends TestCase
{
    use FakesLicenseServer;
    use RefreshDatabase;

    protected const STORE = 'https://market.example.com/shop';

    protected User $admin;

    protected User $member;

    protected string $root;

    /** Signing key pair of the pretend market server. */
    protected string $secretKey;

    protected array $packages = [];

    protected function setUp(): void
    {
        parent::setUp();

        app(Installer::class)->installDefaults();
        $this->admin = User::factory()->inGroup('admin')->create();
        $this->member = User::factory()->create();
        $this->withoutMiddleware(ThrottleRequests::class);

        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);

        // Add-ons and themes are written to throw-away folders.
        $this->root = storage_path('framework/testing/market-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->root.'/addons');
        config([
            'forum.addons_path' => $this->root.'/addons',
            'forum.themes_path' => $this->root.'/themes',
            'forum.store.url' => self::STORE,
            'forum.store.public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
        ]);
        app(Manager::class)->forget();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_the_market_lists_buys_and_installs_signed_packages(): void
    {
        $theme = $this->zip(['theme.json' => json_encode(['id' => 'Acme/Night', 'title' => 'Gece', 'version' => '1.2.0', 'colors' => ['accent' => '#e8590c', 'header' => '#22262d', 'bogus' => 'red'], 'css' => 'theme.css']),
            'theme.css' => ".brand { letter-spacing: 1px; }\n</style><script>alert(1)</script>", 'evil.php' => '<?php echo 1;']);
        $module = $this->zip(['Hello/addon.json' => json_encode(['id' => 'Acme/Hello', 'title' => 'Merhaba Modülü', 'version' => '2.0.0', 'namespace' => 'Acme\\Hello']),
            'Hello/src/Addon.php' => "<?php\n\nnamespace Acme\\Hello;\n\nclass Addon extends \\App\\Forum\\Addons\\Addon {}\n", 'Hello/../../escape.php' => '<?php']);

        $owned = ['gece' => true, 'merhaba' => false];
        $orders = [];
        $this->fakeStore([
            ['slug' => 'gece', 'type' => 'theme', 'package_id' => 'Acme/Night', 'title' => 'Gece teması', 'price' => 0, 'version' => '1.2.0', 'zip' => $theme],
            ['slug' => 'merhaba', 'type' => 'module', 'package_id' => 'Acme/Hello', 'title' => 'Merhaba Modülü', 'price' => 450, 'version' => '2.0.0', 'zip' => $module],
            ['slug' => 'gelecek', 'type' => 'module', 'package_id' => 'Acme/Future', 'title' => 'Gelecek Modülü', 'price' => 0, 'version' => '1.0.0', 'min_core' => '99.0.0', 'zip' => $module],
        ], $owned, $orders);

        $this->actingAs($this->member)->get(route('admin.market'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.market'))->assertOk()
            ->assertSee('Gece teması')->assertSee('Merhaba Modülü')->assertSee('450 ₺')->assertSee('Ücretsiz')->assertSee('Satın al');

        // Every request identifies this installation with the same random key, and never with anything else about the admin.
        Http::assertSent(fn (ClientRequest $request) => preg_match('/^[A-Za-z0-9]{48}$/', $request->header('X-Site-Key')[0]) === 1
            && $request->header('X-Site-Url')[0] === url('/') && ! str_contains(json_encode($request->headers()), $this->admin->email));

        // Buying sends the administrator to the market's own payment page.
        $this->post(route('admin.market.buy'), ['slug' => 'merhaba'])->assertRedirect(self::STORE.'/pay.php?o=tok-merhaba');
        $this->assertSame(['merhaba'], $orders);
        $this->post(route('admin.market.buy'), ['slug' => 'yok'])->assertNotFound();

        // Not paid yet: the market refuses the download and nothing is installed.
        $this->post(route('admin.market.install'), ['slug' => 'merhaba', 'current_password' => 'password'])->assertSessionHas('error');
        $this->assertSame([], app(Manager::class)->all());
        $this->post(route('admin.market.install'), ['slug' => 'merhaba', 'current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->post(route('admin.market.install'), ['slug' => 'gelecek', 'current_password' => 'password'])->assertSessionHas('error');

        // Paid: the module arrives in the add-ons folder, switched off, and only with files that belong inside it.
        $owned['merhaba'] = true;
        $this->post(route('admin.market.install'), ['slug' => 'merhaba', 'current_password' => 'password'])->assertSessionHas('success');
        app(Manager::class)->forget();
        $this->assertSame('2.0.0', app(Manager::class)->all()['Acme/Hello']['version']);
        $this->assertFalse(app(Manager::class)->isEnabled('Acme/Hello'));
        $this->assertFileExists($this->root.'/addons/Acme/Hello/src/Addon.php');
        $this->assertFileDoesNotExist($this->root.'/addons/escape.php');
        $this->assertFileDoesNotExist($this->root.'/escape.php');
        $this->get(route('admin.market', ['refresh' => 1]))->assertOk()->assertSee('Kurulu 2.0.0');

        // The theme: only its manifest and stylesheet are kept.
        $this->post(route('admin.market.install'), ['slug' => 'gece', 'current_password' => 'password'])->assertSessionHas('success');
        $this->assertFileExists($this->root.'/themes/Acme/Night/theme.css');
        $this->assertFileDoesNotExist($this->root.'/themes/Acme/Night/evil.php');
        $this->get('/')->assertOk()->assertDontSee('id="theme-css"', false);

        $this->post(route('admin.market.theme'), ['id' => 'Acme/Night', 'action' => 'activate'])->assertSessionHas('success');
        $page = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('<style id="theme-css">.brand { letter-spacing: 1px; }', $page);
        $this->assertStringNotContainsString('</style><script>alert(1)', $page, 'A theme stylesheet cannot close its own <style> element.');
        $this->assertSame('#e8590c', app(Theme::class)->colors()['accent']);
        $this->assertArrayNotHasKey('bogus', app(Theme::class)->colors());

        // A theme in use cannot be deleted; switched off, the default colours return.
        $this->post(route('admin.market.theme'), ['id' => 'Acme/Night', 'action' => 'delete'])->assertSessionHas('error');
        $this->post(route('admin.market.theme'), ['id' => 'Acme/Night', 'action' => 'deactivate'])->assertSessionHas('success');
        $this->assertSame(app(Theme::class)->defaults(), app(Theme::class)->colors());
        $this->get('/')->assertOk()->assertDontSee('id="theme-css"', false);
        $this->post(route('admin.market.theme'), ['id' => 'Acme/Night', 'action' => 'delete'])->assertSessionHas('success');
        $this->assertSame([], app(Themes::class)->all());

        // Safe mode ignores an active theme's stylesheet.
        $this->post(route('admin.market.install'), ['slug' => 'gece', 'current_password' => 'password']);
        $this->post(route('admin.market.theme'), ['id' => 'Acme/Night', 'action' => 'activate']);
        config(['forum.safe_mode' => true]);
        $this->get('/')->assertOk()->assertDontSee('id="theme-css"', false);
    }

    public function test_a_package_that_fails_its_signature_check_is_never_installed(): void
    {
        $good = $this->zip(['addon.json' => json_encode(['id' => 'Acme/Hello', 'title' => 'İyi', 'version' => '1.0.0', 'namespace' => 'Acme\\Hello'])]);
        $evil = $this->zip(['addon.json' => json_encode(['id' => 'Acme/Hello', 'title' => 'Kötü', 'version' => '1.0.0', 'namespace' => 'Acme\\Hello']), 'backdoor.php' => '<?php system($_GET[0]);']);
        $item = ['slug' => 'merhaba', 'type' => 'module', 'package_id' => 'Acme/Hello', 'title' => 'Merhaba', 'price' => 0, 'version' => '1.0.0'];
        $owned = ['merhaba' => true];
        $orders = [];
        $install = fn () => $this->actingAs($this->admin)->post(route('admin.market.install'), ['slug' => 'merhaba', 'current_password' => 'password']);

        // 1. The file was swapped on the way: the signature belongs to the genuine package.
        $this->fakeStore([$item + ['zip' => $good, 'serve' => $evil]], $owned, $orders);
        $install()->assertSessionHas('error');
        // 2. Signed with somebody else's key.
        $this->fakeStore([$item + ['zip' => $evil, 'key' => sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())]], $owned, $orders);
        $install()->assertSessionHas('error');
        // 3. A genuine signature of a different package, replayed for this one.
        $this->fakeStore([$item + ['zip' => $evil, 'sign_as' => 'Acme/Other']], $owned, $orders);
        $install()->assertSessionHas('error');
        // 4. Correctly signed, but the archive claims to be another add-on.
        $this->fakeStore([['package_id' => 'Acme/Other'] + $item + ['zip' => $good]], $owned, $orders);
        $install()->assertSessionHas('error');
        // 5. No signature at all.
        $this->fakeStore([$item + ['zip' => $evil, 'unsigned' => true]], $owned, $orders);
        $install()->assertSessionHas('error');

        app(Manager::class)->forget();
        $this->assertSame([], app(Manager::class)->all());
        $this->assertFileDoesNotExist($this->root.'/addons/Acme/Hello/backdoor.php');

        // The genuine one goes through.
        $this->fakeStore([$item + ['zip' => $good]], $owned, $orders);
        $install()->assertSessionHas('success');
        $this->assertFileExists($this->root.'/addons/Acme/Hello/addon.json');
    }

    public function test_the_market_page_copes_with_a_missing_or_broken_market(): void
    {
        config(['forum.store.url' => '']);
        $this->actingAs($this->admin)->get(route('admin.market'))->assertOk()->assertSee('henüz tanımlı değil');

        config(['forum.store.url' => self::STORE, 'forum.store.public_key' => 'not-a-key']);
        $this->get(route('admin.market'))->assertOk()->assertSee('henüz tanımlı değil');

        config(['forum.store.public_key' => base64_encode(str_repeat('k', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES))]);
        Http::fake(fn () => Http::response('<html>bakımdayız</html>', 500));
        $this->get(route('admin.market'))->assertOk()->assertSee('ulaşılamadı');
        $this->post(route('admin.market.buy'), ['slug' => 'x'])->assertNotFound();

        // A market that tries to send the administrator somewhere else is not followed.
        Cache::flush();
        Http::swap(new Factory);
        Http::fake(fn (ClientRequest $request) => str_contains($request->url(), 'action=order')
            ? Http::response(['status' => 'pending', 'pay_url' => 'https://evil.example.com/pay'])
            : Http::response(['store' => ['name' => 'M'], 'items' => [['slug' => 'x', 'type' => 'module', 'package_id' => 'A/B', 'title' => 'X', 'summary' => '', 'description' => '', 'price' => 10, 'version' => '1.0.0', 'min_core' => '', 'screenshot' => null, 'owned' => false, 'order' => null]]]));
        $this->post(route('admin.market.buy'), ['slug' => 'x'])->assertSessionHas('error');
    }

    public function test_a_theme_can_be_uploaded_as_a_file(): void
    {
        $upload = fn (string $path) => new UploadedFile($path, 'tema.zip', 'application/zip', null, true);

        $this->actingAs($this->member)->post(route('admin.market.theme.upload'), ['package' => $upload($this->zip(['theme.json' => '{}']))])->assertForbidden();
        // The password is asked again, as for every package that is put on the server by hand.
        $this->actingAs($this->admin)->post(route('admin.market.theme.upload'), ['package' => $upload($this->zip(['theme.json' => '{}']))])->assertSessionHasErrors('current_password');
        $this->post(route('admin.market.theme.upload'), ['current_password' => 'password', 'package' => $upload($this->zip(['readme.txt' => 'x']))])->assertSessionHas('error');
        $this->post(route('admin.market.theme.upload'), ['current_password' => 'password', 'package' => $upload($this->zip(['theme.json' => json_encode(['id' => '../../etc', 'title' => 'x'])]))])->assertSessionHas('error');

        $this->post(route('admin.market.theme.upload'), ['current_password' => 'password', 'package' => $upload($this->zip([
            'tema/theme.json' => json_encode(['id' => 'Benim/Tema', 'title' => 'Benim Temam', 'version' => '0.9', 'colors' => ['accent' => '#1f9d6b'], 'css' => '../../../public/index.css']),
        ]))])->assertSessionHas('success');

        $this->assertSame('Benim Temam', app(Themes::class)->all()['Benim/Tema']['title']);
        $this->get(route('admin.market'))->assertOk()->assertSee('Benim Temam')->assertSee('Bu temayı kullan');
        $this->post(route('admin.market.theme'), ['id' => 'Benim/Tema', 'action' => 'activate'])->assertSessionHas('success');
        // The stylesheet name pointed outside the theme: nothing is read from there.
        $this->get('/')->assertOk()->assertDontSee('id="theme-css"', false);
        $this->assertSame('#1f9d6b', app(Theme::class)->colors()['accent']);
    }

    public function test_the_forum_hears_about_updates_and_updates_itself_from_the_market(): void
    {
        // The "application" that gets updated is a throw-away folder.
        config(['forum.update_root' => $this->root.'/app-root']);
        File::ensureDirectoryExists($this->root.'/app-root');
        $version = config('forum.product.version');

        $core = $this->zip([
            'meydan/config/forum.php' => "<?php return ['product' => ['version' => '99.0.0']];",
            'meydan/app/New.php' => '<?php // new file',
            'meydan/.env' => 'APP_KEY=stolen',
            'meydan/storage/app/x.txt' => 'never',
            'meydan/../outside.php' => '<?php',
        ]);
        $module = $this->zip(['addon.json' => json_encode(['id' => 'Acme/Hello', 'title' => 'Merhaba', 'version' => '1.0.0', 'namespace' => 'Acme\\Hello'])]);
        $newer = $this->zip(['addon.json' => json_encode(['id' => 'Acme/Hello', 'title' => 'Merhaba', 'version' => '1.5.0', 'namespace' => 'Acme\\Hello'])]);
        $owned = ['merhaba' => true];
        $orders = [];
        $items = fn (string $moduleVersion, string $moduleZip, string $coreVersion) => [
            ['slug' => 'forum', 'type' => 'core', 'package_id' => 'Meydan/Core', 'title' => 'Forum', 'price' => 0, 'version' => $coreVersion, 'zip' => $core],
            ['slug' => 'merhaba', 'type' => 'module', 'package_id' => 'Acme/Hello', 'title' => 'Merhaba Modülü', 'price' => 300, 'version' => $moduleVersion, 'zip' => $moduleZip],
        ];

        // Nothing newer than what is installed: no badge, no update button.
        $this->fakeStore($items('1.0.0', $module, $version), $owned, $orders);
        $this->actingAs($this->admin)->post(route('admin.market.install'), ['slug' => 'merhaba', 'current_password' => 'password'])->assertSessionHas('success');
        app(Manager::class)->forget();
        $this->assertSame(0, app(Client::class)->checkUpdates());
        $this->get(route('admin.market'))->assertOk()->assertSee('Forum yazılımı')->assertSee('Güncel')->assertDontSee('sürümüne güncelle');
        $this->post(route('admin.market.core'), ['current_password' => 'password'])->assertSessionHas('success');
        $this->assertFileDoesNotExist($this->root.'/app-root/app/New.php');

        // The market gets a new forum version and a new module version: the scheduled check counts both.
        $this->fakeStore($items('1.5.0', $newer, '99.0.0'), $owned, $orders);
        $this->assertSame(2, app(Client::class)->checkUpdates());
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('2 güncelleme var');
        $this->get(route('admin.market'))->assertOk()->assertSee('99.0.0 sürümüne güncelle')->assertSee('Güncelleme: 1.5.0');
        // The core item is not something to "install" as a module.
        $this->post(route('admin.market.install'), ['slug' => 'forum', 'current_password' => 'password'])->assertNotFound();

        $this->post(route('admin.market.core'), ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->actingAs($this->member)->post(route('admin.market.core'), ['current_password' => 'password'])->assertForbidden();
        $this->assertFileDoesNotExist($this->root.'/app-root/config/forum.php');

        $this->actingAs($this->admin)->post(route('admin.market.core'), ['current_password' => 'password'])->assertRedirect(route('admin.market'))->assertSessionHas('success')->assertSessionHas('update_log');
        $this->assertStringContainsString("'99.0.0'", file_get_contents($this->root.'/app-root/config/forum.php'));
        $this->assertFileExists($this->root.'/app-root/app/New.php');
        // The environment file, the storage folder and anything outside the application are never taken from a package.
        $this->assertFileDoesNotExist($this->root.'/app-root/.env');
        $this->assertFileDoesNotExist($this->root.'/app-root/storage/app/x.txt');
        $this->assertFileDoesNotExist($this->root.'/outside.php');

        // The module update installs over the old copy.
        $this->post(route('admin.market.install'), ['slug' => 'merhaba', 'current_password' => 'password'])->assertSessionHas('success');
        app(Manager::class)->forget();
        $this->assertSame('1.5.0', app(Manager::class)->all()['Acme/Hello']['version']);
        $this->get(route('admin.market'))->assertOk();
        $this->assertSame(1, app(Client::class)->pendingUpdates(), 'Only the forum itself is still behind (the test never changes the running version).');

        // A forged forum update is refused like any other package.
        File::deleteDirectory($this->root.'/app-root');
        $this->fakeStore([['slug' => 'forum', 'type' => 'core', 'package_id' => 'Meydan/Core', 'title' => 'Forum', 'price' => 0, 'version' => '99.0.0', 'zip' => $core, 'key' => sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())]], $owned, $orders);
        $this->post(route('admin.market.core'), ['current_password' => 'password'])->assertSessionHas('error');
        $this->assertDirectoryDoesNotExist($this->root.'/app-root/config');
    }

    public function test_bundles_and_update_periods_are_shown(): void
    {
        $owned = ['suresi-biten' => true, 'suren' => true];
        $orders = [];
        $zip = $this->zip(['addon.json' => json_encode(['id' => 'Acme/Hello', 'title' => 'x', 'version' => '1.0.0', 'namespace' => 'Acme\\Hello'])]);
        $this->fakeStore([
            ['slug' => 'hepsi', 'type' => 'bundle', 'package_id' => '', 'title' => 'Hepsi Bir Arada', 'price' => 900, 'version' => '', 'zip' => $zip, 'extra' => ['includes' => ['Modül A', 'Tema B']]],
            ['slug' => 'suresi-biten', 'type' => 'module', 'package_id' => 'Acme/Old', 'title' => 'Süresi Biten', 'price' => 400, 'version' => '2.0.0', 'zip' => $zip,
                'extra' => ['expires_at' => '2026-01-01 00:00:00', 'expired' => true, 'renewable' => true, 'renew_price' => 150, 'term_days' => 365]],
            ['slug' => 'suren', 'type' => 'module', 'package_id' => 'Acme/Fresh', 'title' => 'Süresi Süren', 'price' => 400, 'version' => '1.0.0', 'zip' => $zip,
                'extra' => ['expires_at' => '2027-06-01 00:00:00', 'expired' => false, 'renewable' => false, 'term_days' => 365]],
            ['slug' => 'yeni', 'type' => 'module', 'package_id' => 'Acme/New', 'title' => 'Henüz Alınmamış', 'price' => 250, 'version' => '1.0.0', 'zip' => $zip, 'extra' => ['term_days' => 365]],
        ], $owned, $orders);

        $page = $this->actingAs($this->admin)->get(route('admin.market'))->assertOk()
            ->assertSee('Paket')->assertSee('İçindekiler:')->assertSee('Modül A, Tema B')
            ->assertSee('Güncelleme süresi doldu')->assertSee('Yenile · 150 ₺')
            ->assertSee('tarihine kadar')
            ->assertSee('365 gün güncelleme içerir')
            ->getContent();
        // An expired licence offers renewal, not a download.
        $this->assertSame(1, substr_count($page, 'name="slug" value="suren"'), 'Only the install dialog of the valid licence.');
        $this->assertStringNotContainsString('id="install-1"', $page);

        // Renewing goes through the same order as buying.
        $this->post(route('admin.market.buy'), ['slug' => 'suresi-biten'])->assertRedirect(self::STORE.'/pay.php?o=tok-suresi-biten');
        // A bundle cannot be "installed"; its items are.
        $this->post(route('admin.market.install'), ['slug' => 'hepsi', 'current_password' => 'password'])->assertNotFound();
    }

    public function test_netgsm_sends_text_messages(): void
    {
        app(Settings::class)->set(['sms.provider' => 'netgsm']);
        $sms = app(Sms::class);
        $this->assertFalse($sms->enabled(), 'Not usable until the account details are entered.');

        app(Settings::class)->set(['sms.netgsm_username' => '8501234567', 'sms.netgsm_password' => 'api-secret', 'sms.netgsm_sender' => 'MEYDAN']);
        $this->assertTrue($sms->enabled());

        $tokens = 0;
        $reject = false;
        Http::fake(function (ClientRequest $request) use (&$tokens, &$reject) {
            if (str_ends_with($request->url(), '/auth/v3/token')) {
                $tokens++;

                return $request['password'] === 'api-secret' ? Http::response(['access_token' => 'token-'.$tokens, 'expires_in' => 3600]) : Http::response(['error' => 'invalid_credentials'], 401);
            }
            if ($request->header('Authorization')[0] === 'Bearer token-1' && $tokens === 1 && $reject) {
                return Http::response(['error' => 'unauthorized'], 401);
            }

            return str_contains($request['messages'][0]['recipient'], '999')
                ? Http::response(['errorCode' => 'NO_DELIVERABLE_MESSAGES'], 422)
                : Http::response(['jobId' => 'j1', 'status' => 'QUEUED', 'accepted' => 1, 'rejected' => 0]);
        });

        $this->assertTrue($sms->send('905321234567', 'Doğrulama kodunuz: 123456'));
        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://ext-api.netgsm.com.tr/auth/v3/token'
            && $request['username'] === '8501234567' && $request['scope'] === 'sms');
        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://ext-api.netgsm.com.tr/sms/v3/send'
            && $request->header('Authorization')[0] === 'Bearer token-1'
            && $request['sender'] === 'MEYDAN' && $request['encoding'] === 'TURKISH'
            && $request['messages'] === [['recipient' => '+905321234567', 'text' => 'Doğrulama kodunuz: 123456']]);

        // The token is reused, a refused message is reported as not sent ...
        $this->assertTrue($sms->send('905321234568', 'x'));
        $this->assertSame(1, $tokens);
        $this->assertFalse($sms->send('905999999999', 'x'));
        // ... and when the token stops working a new one is fetched once.
        $reject = true;
        $this->assertTrue($sms->send('905321234567', 'x'));
        $this->assertSame(2, $tokens);

        // Wrong password: no token, no message, no exception.
        Cache::flush();
        app(Settings::class)->set(['sms.netgsm_password' => 'wrong']);
        $this->assertFalse($sms->send('905321234567', 'x'));
    }

    public function test_a_sold_module_from_the_market_waits_for_its_code_and_an_update_keeps_a_licensed_one_running(): void
    {
        $package = fn (string $version, bool $sold) => $this->zip([
            'addon.json' => json_encode(['id' => 'Acme/Hello', 'title' => 'Merhaba', 'version' => $version, 'namespace' => 'Acme\\SoldHello'] + ($sold ? ['license' => 'wd-forum-modul-hello'] : [])),
            'src/Addon.php' => "<?php\n\nnamespace Acme\\SoldHello;\n\nclass Addon extends \\App\\Forum\\Addons\\Addon\n{\n    public function boot(): void {}\n}\n",
        ]);
        $owned = ['merhaba' => true];
        $orders = [];
        $market = fn (string $version, bool $sold, ?string $code = null) => $this->fakeStore([
            ['slug' => 'merhaba', 'type' => 'module', 'package_id' => 'Acme/Hello', 'title' => 'Merhaba Modülü', 'price' => 300, 'version' => $version, 'zip' => $package($version, $sold),
                'extra' => ['licensed' => $sold, 'license_key' => $code]],
        ], $owned, $orders);
        $install = fn () => $this->post(route('admin.market.install'), ['slug' => 'merhaba', 'current_password' => 'password']);
        $toggle = fn (string $action) => $this->post(route('admin.addons.toggle'), ['id' => 'Acme/Hello', 'action' => $action]);
        $manager = app(Manager::class);

        // An older copy from before licence codes: installed and running.
        $this->fakeLicenseServer();
        $code = $this->issueLicense('wd-forum-modul-hello');
        $market('1.0.0', false);
        $this->actingAs($this->admin);
        $install()->assertSessionHas('success');
        $toggle('enable')->assertSessionHas('success');
        $this->assertTrue($manager->isEnabled('Acme/Hello'));

        // The market now ships it as a sold module and shows the buyer the code. Updating without entering
        // the code leaves the new copy installed but off.
        $market('1.1.0', true, $code);
        $this->answerLicenseCalls();
        $this->get(route('admin.market'))->assertOk()->assertSee($code);
        $install()->assertSessionHas('success', '"Merhaba" modülü kuruldu. Açmak için lisans kodunu Eklentiler sayfasında girin.');
        $this->assertSame('1.1.0', $manager->all()['Acme/Hello']['version']);
        $this->assertFalse($manager->isEnabled('Acme/Hello'));
        $toggle('enable')->assertSessionHas('error');

        // With the code entered it is switched on again…
        $this->post(route('admin.license.activate'), ['license_key' => $code, 'product' => 'wd-forum-modul-hello'])->assertSessionHas('success');
        $toggle('enable')->assertSessionHas('success');
        $this->assertTrue($manager->isEnabled('Acme/Hello'));

        // …and from then on an update from the market replaces it without stopping it.
        $market('1.2.0', true, $code);
        $this->answerLicenseCalls();
        $install()->assertSessionHas('success');
        $this->assertSame('1.2.0', $manager->all()['Acme/Hello']['version']);
        $this->assertTrue($manager->isEnabled('Acme/Hello'));

        // A code entered before the sold copy arrives (the order the vendor's own forum is moved in) works too.
        $toggle('disable');
        $this->post(route('admin.license.release'), ['product' => 'wd-forum-modul-hello'])->assertSessionHas('success');
        $market('1.0.0', false);
        $install()->assertSessionHas('success');
        $toggle('enable')->assertSessionHas('success');
        $market('1.3.0', true, $code);
        $this->answerLicenseCalls();
        $this->post(route('admin.license.activate'), ['license_key' => $code])->assertSessionHas('success', 'Lisans etkin. Ait olduğu modül ya da tema bu foruma henüz kurulmamış.');
        $install()->assertSessionHas('success');
        $this->assertSame('1.3.0', $manager->all()['Acme/Hello']['version']);
        $this->assertTrue($manager->isEnabled('Acme/Hello'), 'The module stopped although its code was entered before the update.');
    }

    // ---- Helpers ---------------------------------------------------------------------------------

    /** @param array<string, string> $files name => contents */
    protected function zip(array $files): string
    {
        $path = $this->root.'/pkg-'.bin2hex(random_bytes(6)).'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    /**
     * A pretend market server. Item options: zip (signed file), serve (file actually sent),
     * key (signing key), sign_as (package id in the signature), unsigned.
     */
    protected function fakeStore(array $items, array &$owned, array &$orders): void
    {
        Cache::flush();
        Http::swap(new Factory);

        Http::fake(function (ClientRequest $request) use ($items, &$owned, &$orders) {
            if (! str_starts_with($request->url(), self::STORE)) {
                return null; // not a request to the market: another stand-in answers
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $find = fn (?string $slug) => collect($items)->firstWhere('slug', $slug);

            if (($query['action'] ?? '') === 'catalog') {
                return Http::response(['store' => ['name' => 'Deneme Market', 'currency' => 'TL', 'currency_symbol' => '₺'], 'items' => array_map(fn (array $item) => [
                    'slug' => $item['slug'], 'type' => $item['type'], 'package_id' => $item['package_id'], 'title' => $item['title'],
                    'summary' => 'Kısa tanıtım', 'description' => '', 'price' => $item['price'], 'version' => $item['version'], 'min_core' => $item['min_core'] ?? '',
                    'size' => 1, 'screenshot' => null, 'updated_at' => '2026-11-19 12:00:00',
                    'owned' => $item['price'] <= 0 || ($owned[$item['slug']] ?? false), 'order' => null,
                ] + ($item['extra'] ?? []), $items)]);
            }

            if (($query['action'] ?? '') === 'order') {
                $orders[] = $request['slug'];

                return Http::response(['status' => 'pending', 'pay_url' => self::STORE.'/pay.php?o=tok-'.$request['slug']]);
            }

            $item = $find($query['slug'] ?? null);
            if (! $item) {
                return Http::response(['error' => 'unknown_item'], 404);
            }
            if ($item['price'] > 0 && ! ($owned[$item['slug']] ?? false)) {
                return Http::response(['error' => 'not_purchased'], 402);
            }

            $sha = hash_file('sha256', $item['zip']);
            $signature = sodium_crypto_sign_detached('meydan-package:'.($item['sign_as'] ?? $item['package_id']).':'.$item['version'].':'.$sha, $item['key'] ?? $this->secretKey);

            return Http::response(file_get_contents($item['serve'] ?? $item['zip']), 200, [
                'Content-Type' => 'application/zip',
                'X-Package-Id' => $item['package_id'],
                'X-Package-Version' => $item['version'],
                'X-Package-Sha256' => $sha,
            ] + (empty($item['unsigned']) ? ['X-Package-Signature' => base64_encode($signature)] : []));
        });
    }
}
