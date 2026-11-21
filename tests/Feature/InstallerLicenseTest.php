<?php

namespace Tests\Feature;

use App\Forum\Installer;
use App\Forum\License\Licenses;
use App\Models\License;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\FakesLicenseServer;
use Tests\TestCase;

/**
 * The web installer and the licence code of the forum software: without a code the vendor's licence server
 * accepts, nothing is installed. The installer makes its own database here, so this class does not use the
 * shared test database.
 */
class InstallerLicenseTest extends TestCase
{
    use FakesLicenseServer;

    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/install-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->root);
        // Not installed: there is no environment file yet.
        config(['forum.install.env_path' => $this->root.'/.env', 'forum.install.sqlite_path' => $this->root.'/board.sqlite']);

        $this->fakeLicenseServer();
    }

    protected function tearDown(): void
    {
        DB::purge();
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /** @param array<string, mixed> $over */
    protected function form(array $over = []): array
    {
        return $over + [
            'board_name' => 'Deneme Forumu', 'url' => 'https://www.yeni-forum.example', 'locale' => 'tr', 'db_driver' => 'sqlite',
            'admin_username' => 'yonetici', 'admin_email' => 'yonetici@example.com', 'admin_password' => 'gizli-parola-1', 'admin_password_confirmation' => 'gizli-parola-1',
        ];
    }

    public function test_the_installer_does_not_go_on_without_a_working_licence_code(): void
    {
        $nothingWritten = function (): void {
            $this->assertFileDoesNotExist($this->root.'/.env');
            $this->assertFalse(is_file($this->root.'/board.sqlite') && filesize($this->root.'/board.sqlite') > 0, 'Tables were created without a licence.');
        };

        $this->get('/install')->assertOk()->assertSee('2. Lisans')->assertSee('name="license_key"', false)
            ->assertSee('Geçerli bir lisans kodu olmadan kurulum ilerlemez.');

        // No code at all.
        $this->post('/install', $this->form())->assertStatus(422)->assertSee('name="license_key"', false);
        $this->assertSame([], $this->licenseServer['calls']);
        $nothingWritten();

        // A code the licence server does not know.
        $this->post('/install', $this->form(['license_key' => 'TST-00000000-FFFFFFFF']))->assertStatus(422)->assertSee('Bu lisans kodu lisans sunucusunda kayıtlı değil.');
        $nothingWritten();

        // The code of a module is not a licence for the forum; the seat it took is given back.
        $module = $this->issueLicense('wd-forum-modul-trade');
        $this->post('/install', $this->form(['license_key' => $module]))->assertStatus(422)->assertSee('Bu lisans kodu başka bir ürüne ait.');
        $this->assertSame([], $this->licenseServer['licenses'][$module]['installs']);
        $nothingWritten();

        // A suspended licence, and a licence server that cannot be reached: no installation either.
        $key = $this->issueLicense('wd-forum');
        $this->licenseServer['licenses'][$key]['status'] = 'askida';
        $this->post('/install', $this->form(['license_key' => $key]))->assertStatus(422)->assertSee('Bu lisans askıya alındı.');
        $this->licenseServer['licenses'][$key]['status'] = 'aktif';
        $this->licenseServer['down'] = true;
        $this->post('/install', $this->form(['license_key' => $key]))->assertStatus(422)->assertSee('Lisans sunucusuna ulaşılamadı.');
        $this->licenseServer['down'] = false;
        $nothingWritten();

        // A copy of the software without a licence server in its configuration cannot be installed at all.
        config(['forum.license.url' => '']);
        $this->get('/install')->assertOk()->assertSee('lisans sunucusu tanımlı değil');
        $this->post('/install', $this->form(['license_key' => $key]))->assertStatus(422)->assertSee('Bu forumda lisans sunucusu tanımlı değil.');
        $nothingWritten();
        $this->assertSame([], $this->licenseServer['licenses'][$key]['installs']);
    }

    public function test_a_valid_code_installs_the_board_and_is_tied_to_its_address(): void
    {
        $key = $this->issueLicense('wd-forum');

        // Something goes wrong after the code was accepted: the seat is given back, so the same code works again.
        $real = app(Installer::class);
        $fail = true;
        $this->partialMock(Installer::class, fn ($mock) => $mock->shouldReceive('installDefaults')->andReturnUsing(function () use (&$fail, $real) {
            if ($fail) {
                $fail = false;
                throw new RuntimeException('disk dolu');
            }
            $real->installDefaults();
        }));
        $this->post('/install', $this->form(['license_key' => $key]))->assertStatus(422)->assertSee('disk dolu');
        $this->assertSame([], $this->licenseServer['licenses'][$key]['installs'], 'A failed installation kept the seat of the licence.');

        $this->post('/install', $this->form(['license_key' => ' '.$key.' ']))->assertOk()->assertSee('yonetici');

        // The board exists, with its administrator and its licence.
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertSame('yonetici', User::firstOrFail()->username);
        $license = License::firstOrFail();
        $this->assertSame(['wd-forum', 'active', $key], [$license->product, $license->status, $license->license_key]);
        $this->assertStringNotContainsString($key, (string) DB::table('licenses')->value('license_key'));

        // The licence server knows the installation by the identity the board kept, at the address that was entered.
        $installs = $this->licenseServer['licenses'][$key]['installs'];
        $this->assertSame(['yeni-forum.example'], array_values($installs));
        $this->assertSame(setting('license.fingerprint'), array_key_first($installs));

        // From now on it is an ordinary licence of the board: valid, and checked like the others.
        $licenses = app(Licenses::class);
        $licenses->forget();
        $this->assertTrue($licenses->valid('wd-forum'));
        $this->assertSame(0, $licenses->refresh());
        $this->assertTrue(app(Licenses::class)->valid('wd-forum'));

        // The installer is closed, and the code cannot install a second board.
        $this->assertStringContainsString('APP_URL=', (string) file_get_contents($this->root.'/.env'));
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->get('/install')->assertNotFound();
    }

    public function test_the_installer_stays_closed_while_the_database_of_an_installed_board_does_not_answer(): void
    {
        $this->post('/install', $this->form(['license_key' => $this->issueLicense('wd-forum')]))->assertOk();
        $this->get('/install')->assertNotFound();

        // The database goes away for a moment (restart, too many connections). A stranger must not get the installer.
        config(['database.connections.sqlite.database' => $this->root.'/gone/board.sqlite']);
        DB::purge();
        $stranger = $this->form(['license_key' => $this->issueLicense('wd-forum'), 'admin_username' => 'davetsiz', 'admin_email' => 'davetsiz@example.com']);
        $this->get('/install')->assertNotFound();
        $this->post('/install', $stranger)->assertNotFound();

        // A board installed by an older version has no marker file: an environment file plus a database error still means "installed".
        $this->assertFileExists($this->root.'/installed');
        File::delete($this->root.'/installed');
        $this->get('/install')->assertNotFound();
        $this->post('/install', $stranger)->assertNotFound();
        $this->assertStringNotContainsString('davetsiz', (string) file_get_contents($this->root.'/.env'));

        // With the database back the board is recognised by its members, and the marker is written for the next time.
        config(['database.connections.sqlite.database' => $this->root.'/board.sqlite']);
        DB::purge();
        $this->get('/install')->assertNotFound();
        $this->assertFileExists($this->root.'/installed');
    }
}
