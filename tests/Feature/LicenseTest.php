<?php

namespace Tests\Feature;

use App\Forum\Addons\Manager;
use App\Forum\Installer;
use App\Forum\License\Licenses;
use App\Forum\Settings;
use App\Forum\Tasks;
use App\Forum\Themes;
use App\Models\License;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesLicenseServer;
use Tests\TestCase;
use ZipArchive;

/**
 * Licence codes: the forum's own, and the separate code every sold module and theme needs before it runs.
 * The licence server is a stand-in that signs like the real one (see FakesLicenseServer).
 */
class LicenseTest extends TestCase
{
    use FakesLicenseServer;
    use RefreshDatabase;

    protected User $admin;

    protected User $member;

    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();

        app(Installer::class)->installDefaults();
        $this->admin = User::factory()->inGroup('admin')->create();
        $this->member = User::factory()->create();
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->root = storage_path('framework/testing/license-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->root.'/addons');
        config(['forum.addons_path' => $this->root.'/addons', 'forum.themes_path' => $this->root.'/themes', 'app.url' => 'https://forum.example.com']);
        app(Manager::class)->forget();

        $this->fakeLicenseServer();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /** @param array<string, string> $files */
    protected function zip(array $files): string
    {
        $path = $this->root.'/'.bin2hex(random_bytes(4)).'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    /** A sold module: its manifest names the licence product it belongs to. */
    protected function installSoldModule(?string $product = 'wd-forum-modul-paid'): void
    {
        $manifest = ['id' => 'Acme/Paid', 'title' => 'Ücretli Modül', 'version' => '1.0.0', 'namespace' => 'Acme\\Paid'] + ($product ? ['license' => $product] : []);
        $result = app(Manager::class)->installZip($this->zip([
            'addon.json' => json_encode($manifest),
            'src/Addon.php' => "<?php\n\nnamespace Acme\\Paid;\n\nclass Addon extends \\App\\Forum\\Addons\\Addon\n{\n    public function boot(): void\n    {\n        app(\App\Forum\Addons\Hooks::class)->listen('admin.dashboard', fn () => '<p>PAID-MODULE-RUNS</p>');\n    }\n}\n",
        ]), 'Acme/Paid');
        $this->assertIsArray($result);
    }

    public function test_a_licence_code_is_checked_with_the_licence_server_and_kept_encrypted(): void
    {
        $licenses = app(Licenses::class);
        $key = $this->issueLicense('wd-forum');

        $this->actingAs($this->member)->get(route('admin.license'))->assertForbidden();
        $this->post(route('admin.license.activate'), ['license_key' => $key])->assertForbidden();

        $this->actingAs($this->admin)->get(route('admin.license'))->assertOk()->assertSee('Lisans kodu ekle')->assertSee('forum.example.com')->assertSee('Henüz lisans kodu girilmedi.');

        // Rubbish never reaches the server; an unknown code is refused by it.
        $this->post(route('admin.license.activate'), ['license_key' => 'x y z'])->assertSessionHas('error');
        $this->assertSame([], $this->licenseServer['calls']);
        $this->post(route('admin.license.activate'), ['license_key' => 'TST-00000000-FFFFFFFF'])->assertSessionHas('error', 'Bu lisans kodu lisans sunucusunda kayıtlı değil.');
        $this->assertSame(0, License::count());

        // The code tells which product it is for.
        $this->post(route('admin.license.activate'), ['license_key' => ' '.$key.' '])->assertSessionHas('success', 'Forumun lisansı etkin.');
        $this->assertTrue($licenses->valid('wd-forum'));
        $this->assertTrue(app(\App\Forum\Edition::class)->licensed());

        // The server learnt this installation and its address; the database holds the code encrypted.
        $install = $this->licenseServer['licenses'][$key]['installs'];
        $this->assertSame(['forum.example.com'], array_values($install));
        $this->assertSame($licenses->fingerprint(), array_key_first($install));
        $this->assertStringNotContainsString($key, (string) DB::table('licenses')->value('license_key'));

        $page = $this->get(route('admin.license'))->assertOk()->assertSee('Etkin')->assertSee('wd-forum')->assertSee('Süresiz');
        $page->assertDontSee($key)->assertSee(substr($key, 0, 5).'••••••••'.substr($key, -4));

        // Releasing gives the seat back and forgets the code.
        $this->post(route('admin.license.release'), ['product' => 'wd-forum'])->assertSessionHas('success');
        $this->assertSame([], $this->licenseServer['licenses'][$key]['installs']);
        $this->assertSame(0, License::count());
        $this->assertFalse(app(Licenses::class)->valid('wd-forum'));
    }

    public function test_answers_that_the_vendor_did_not_sign_are_not_believed(): void
    {
        $licenses = app(Licenses::class);
        $key = $this->issueLicense('wd-forum');
        $stranger = sodium_crypto_sign_keypair();

        // A token signed with somebody else's key (a home-made licence server).
        $this->licenseServer['signer'] = sodium_crypto_sign_secretkey($stranger);
        $this->assertSame('Lisans sunucusunun yanıtı doğrulanamadı.', $licenses->activate($key));
        $this->assertFalse($licenses->valid('wd-forum'));
        $this->licenseServer['signer'] = sodium_crypto_sign_secretkey($this->licenseServer['server']);

        // The right server key, but a chain that does not start at the vendor's root key.
        $realRoot = $this->licenseServer['root'];
        $this->licenseServer['root'] = sodium_crypto_sign_secretkey($stranger);
        $this->assertSame('Lisans sunucusunun yanıtı doğrulanamadı.', $licenses->activate($key));
        $this->licenseServer['root'] = $realRoot;

        // A forum that trusts another root key does not accept this server at all.
        $pinned = config('forum.license.root_key');
        config(['forum.license.root_key' => bin2hex(sodium_crypto_sign_publickey($stranger))]);
        $this->assertIsString($licenses->activate($key));
        config(['forum.license.root_key' => $pinned]);

        $this->assertIsArray($licenses->activate($key));
        $this->assertTrue($licenses->valid('wd-forum'));

        // A token copied from another installation, or edited in the database, is worth nothing.
        $row = License::firstOrFail();
        $row->update(['token' => $this->licenseToken(['anahtar' => $key, 'urun' => 'wd-forum', 'parmak' => str_repeat('a', 40), 'exp' => now()->getTimestamp() + 3600])]);
        $licenses->forget();
        $this->assertFalse($licenses->valid('wd-forum'), 'A token made for another installation was accepted.');

        $row->update(['token' => substr((string) $row->token, 0, -6).'AAAAAA']);
        $licenses->forget();
        $this->assertFalse($licenses->valid('wd-forum'));

        // Without a licence server in the configuration nothing can be activated.
        config(['forum.license.url' => '']);
        $this->assertSame('Bu forumda lisans sunucusu tanımlı değil.', $licenses->activate($key));
    }

    public function test_a_sold_module_runs_only_with_its_own_licence_code(): void
    {
        $manager = app(Manager::class);
        $this->installSoldModule();
        $toggle = fn (string $action) => $this->post(route('admin.addons.toggle'), ['id' => 'Acme/Paid', 'action' => $action]);

        // Without a code it cannot be switched on, and the page says what is missing.
        $this->actingAs($this->admin)->get(route('admin.addons'))->assertOk()->assertSee('Lisans kodu gerekli')->assertSee('Bu modülün lisans kodu');
        $toggle('enable')->assertSessionHas('error');
        $this->assertFalse($manager->isEnabled('Acme/Paid'));
        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('PAID-MODULE-RUNS');
        $this->get(route('admin.license'))->assertOk()->assertSee('Lisans kodu bekleyenler')->assertSee('Ücretli Modül');

        // The code of another product does not open it, and the seat that code took is given back.
        $other = $this->issueLicense('wd-forum-modul-other');
        $this->post(route('admin.license.activate'), ['license_key' => $other, 'product' => 'wd-forum-modul-paid'])->assertSessionHas('error', 'Bu lisans kodu başka bir ürüne ait.');
        $this->assertSame([], $this->licenseServer['licenses'][$other]['installs']);
        $toggle('enable')->assertSessionHas('error');

        // Its own code does.
        $key = $this->issueLicense('wd-forum-modul-paid');
        $this->post(route('admin.license.activate'), ['license_key' => $key, 'product' => 'wd-forum-modul-paid'])->assertSessionHas('success');
        $this->get(route('admin.addons'))->assertOk()->assertSee('Lisanslı')->assertDontSee('Lisans kodu gerekli');
        $toggle('enable')->assertSessionHas('success');
        $this->assertTrue($manager->isEnabled('Acme/Paid'));
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('PAID-MODULE-RUNS');

        // The same code is refused on a second forum: one purchase, one forum.
        $settings = app(Settings::class);
        $mine = $settings->get('license.fingerprint');
        $settings->set(['license.fingerprint' => str_repeat('b', 40)]);
        $this->assertSame('Bu lisans başka bir forumda kullanılıyor. Önce orada serbest bırakın.', app(Licenses::class)->activate($key));
        $settings->set(['license.fingerprint' => $mine]);
        app(Licenses::class)->forget();
        $this->assertTrue($manager->isEnabled('Acme/Paid'));

        // A module without the line in its manifest is free to run, as before.
        $toggle('disable');
        $this->installSoldModule(null);
        $this->post(route('admin.license.release'), ['product' => 'wd-forum-modul-paid']);
        $toggle('enable')->assertSessionHas('success');
    }

    public function test_a_suspended_licence_stops_its_module_at_the_next_check(): void
    {
        $manager = app(Manager::class);
        $this->installSoldModule();
        $key = $this->licenseFor('wd-forum-modul-paid');
        $this->assertNull($manager->enable('Acme/Paid'));
        $this->assertSame(['Acme/Paid'], $manager->enabledIds());

        // The licence server is down: the last signed answer keeps the module running…
        $this->licenseServer['down'] = true;
        $this->assertSame(0, app(Licenses::class)->refresh());
        $this->assertSame(['Acme/Paid'], $manager->enabledIds());

        // …but not for ever.
        $this->travel(8)->days();
        app(Licenses::class)->forget();
        $this->assertSame([], $manager->enabledIds());
        $this->assertSame(['Acme/Paid'], $manager->lockedIds());

        // Back in reach, the module comes back by itself (it was never switched off by the administrator).
        $this->licenseServer['down'] = false;
        app(Licenses::class)->refresh();
        $this->assertSame(['Acme/Paid'], $manager->enabledIds());

        // The vendor suspends the licence: the hourly task hears it and the module stops.
        $this->licenseServer['licenses'][$key]['status'] = 'askida';
        $this->assertContains('license.refresh', array_keys(app(Tasks::class)->definitions()));
        $this->assertSame(1, app(Licenses::class)->refresh());
        $this->assertSame([], $manager->enabledIds());
        $this->assertSame(['Acme/Paid'], $manager->lockedIds());

        $this->actingAs($this->admin)->get(route('admin.addons'))->assertOk()->assertSee('Durduruldu: lisans yok');
        $this->get(route('admin.license'))->assertOk()->assertSee('Geçerli değil')->assertSee('Bu lisans askıya alındı.');
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('İlgilenilmesi gereken lisanslar');
        $this->post(route('admin.addons.toggle'), ['id' => 'Acme/Paid', 'action' => 'enable'])->assertSessionHas('error');

        // Reinstated: "Check now" brings it back.
        $this->licenseServer['licenses'][$key]['status'] = 'aktif';
        $this->post(route('admin.license.check'))->assertSessionHas('success');
        $this->assertSame(['Acme/Paid'], app(Manager::class)->enabledIds());

        // A seat the vendor released on the server is simply taken again while it is free.
        $this->licenseServer['licenses'][$key]['installs'] = [];
        $this->assertSame(0, app(Licenses::class)->refresh());
        $this->assertCount(1, $this->licenseServer['licenses'][$key]['installs']);

        // An expired licence is a refusal like any other.
        $this->licenseServer['licenses'][$key]['ends'] = now()->getTimestamp() - 60;
        $this->post(route('admin.license.check'))->assertSessionHas('error');
        $this->assertSame([], app(Manager::class)->enabledIds());
    }

    public function test_a_sold_theme_needs_its_licence_code_too(): void
    {
        $themes = app(Themes::class);
        $package = fn (string $version) => $this->zip([
            'theme.json' => json_encode(['id' => 'Acme/Neon', 'title' => 'Neon Tema', 'version' => $version, 'license' => 'wd-forum-tema-neon', 'colors' => ['accent' => '#aa00ff'], 'css' => 'neon.css']),
            'neon.css' => '.brand { letter-spacing: 3px; }',
        ]);
        $this->assertIsArray($themes->installZip($package('1.0.0')));
        $use = fn (string $action) => $this->post(route('admin.market.theme'), ['id' => 'Acme/Neon', 'action' => $action]);

        $this->actingAs($this->admin)->get(route('admin.market'))->assertOk()->assertSee('Lisans kodu gerekli')->assertSee('Bu temanın lisans kodu');
        $use('activate')->assertSessionHas('error', 'Önce bu temanın lisans kodunu girin.');
        $this->assertNull($themes->activeId());
        $this->get('/')->assertOk()->assertDontSee('letter-spacing: 3px', false);

        $key = $this->issueLicense('wd-forum-tema-neon');
        $this->post(route('admin.license.activate'), ['license_key' => $key, 'product' => 'wd-forum-tema-neon'])->assertSessionHas('success');
        $use('activate')->assertSessionHas('success');
        $this->assertSame('Acme/Neon', app(Themes::class)->activeId());
        $this->get('/')->assertOk()->assertSee('letter-spacing: 3px', false)->assertSee('--accent:#aa00ff', false);

        // An update of the theme in use keeps it in use while the licence holds.
        $this->assertIsArray(app(Themes::class)->installZip($package('1.1.0')));
        $this->assertSame('Acme/Neon', app(Themes::class)->activeId());

        // The licence is cancelled: at the next check the theme is switched off and the default colours return.
        $this->licenseServer['licenses'][$key]['status'] = 'iptal';
        $this->post(route('admin.license.check'))->assertSessionHas('error');
        $this->assertNull(app(Themes::class)->activeId());
        $this->assertNull(app(Themes::class)->chosenId());
        $this->get('/')->assertOk()->assertDontSee('letter-spacing: 3px', false)->assertDontSee('--accent:#aa00ff', false);
    }

    public function test_the_market_page_shows_the_licence_code_of_a_purchase(): void
    {
        $pair = sodium_crypto_sign_keypair();
        config(['forum.store.url' => 'https://market.example.com/shop', 'forum.store.public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);
        $item = fn (array $over) => $over + ['type' => 'module', 'summary' => '', 'description' => '', 'min_core' => '', 'size' => 100, 'screenshot' => null, 'updated_at' => now()->toDateTimeString(),
            'expires_at' => null, 'expired' => false, 'renewable' => false, 'term_days' => 0, 'renew_price' => 0, 'includes' => [], 'order' => null];

        Http::fake(['market.example.com/*' => Http::response(['store' => ['name' => 'Market', 'currency_symbol' => '₺'], 'items' => [
            $item(['slug' => 'bir', 'package_id' => 'Acme/One', 'title' => 'Birinci Modül', 'price' => 100, 'version' => '1.0.0', 'owned' => true, 'licensed' => true, 'license_key' => 'MYD-ONE1-ONE2-ONE3']),
            $item(['slug' => 'iki', 'package_id' => 'Acme/Two', 'title' => 'İkinci Modül', 'price' => 100, 'version' => '1.0.0', 'owned' => true, 'licensed' => true, 'license_key' => null]),
            $item(['slug' => 'uc', 'package_id' => 'Acme/Three', 'title' => 'Üçüncü Modül', 'price' => 100, 'version' => '1.0.0', 'owned' => false, 'licensed' => true]),
        ]])]);

        $this->actingAs($this->admin)->get(route('admin.market'))->assertOk()
            ->assertSee('MYD-ONE1-ONE2-ONE3')
            ->assertSee('Bu satın almanın lisans kodu hazırlanıyor.');
    }

    public function test_the_command_line_installer_asks_for_the_licence_code_of_the_forum_too(): void
    {
        // A board without an administrator and without a code: this run would install it.
        User::query()->delete();
        $code = $this->issueLicense('wd-forum');
        $account = ['--username' => 'kurucu', '--email' => 'kurucu@example.com', '--password' => 'gizli-parola-1'];

        $this->artisan('forum:install', $account + ['--license' => 'TST-YANLIS-KOD1'])->assertFailed();
        $this->assertSame(0, User::count());
        $this->assertNull(License::where('product', 'wd-forum')->first());

        // A code of something else is not a code of the forum.
        $this->artisan('forum:install', $account + ['--license' => $this->issueLicense('wd-forum-modul-hello')])->assertFailed();
        $this->assertSame(0, User::count());

        $this->artisan('forum:install', $account + ['--license' => $code])->assertSuccessful();
        $this->assertSame(1, User::where('username', 'kurucu')->count());
        app(Licenses::class)->forget();
        $this->assertTrue(app(Licenses::class)->valid('wd-forum'));
        $this->assertSame('forum.example.com', app(Licenses::class)->payload('wd-forum')['domain'] ?? null);

        // Installed: a later run only brings the tables up to date and asks nothing.
        $this->artisan('forum:install')->assertSuccessful();
    }
}
