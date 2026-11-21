<?php

namespace Tests\Feature;

use Acme\Hello\Addon;
use App\Forum\Addons\Hooks;
use App\Forum\Addons\Manager;
use App\Forum\Customizer;
use App\Forum\Installer;
use App\Forum\Posting;
use App\Forum\Settings;
use App\Models\Node;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/** Custom CSS / HTML / logo, the phrase editor, the template editor and add-ons. */
class PhaseFiveCustomiseTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $member;

    protected string $addonRoot;

    protected function setUp(): void
    {
        parent::setUp();

        app(Installer::class)->installDefaults();
        $this->admin = User::factory()->inGroup('admin')->create();
        $this->member = User::factory()->create();

        // Add-ons and template overrides are written to throw-away folders.
        $this->addonRoot = storage_path('framework/testing/addons-'.bin2hex(random_bytes(4)));
        config(['forum.addons_path' => $this->addonRoot]);
        File::ensureDirectoryExists($this->addonRoot);
        app(Manager::class)->forget();   // it looked at the real folder while the application started
        File::deleteDirectory(storage_path('app/templates'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->addonRoot);
        File::deleteDirectory(storage_path('app/templates'));

        parent::tearDown();
    }

    public function test_custom_css_html_and_logo(): void
    {
        Storage::fake('public');

        $this->actingAs($this->member)->post(route('admin.appearance.extras'), ['custom_css' => 'x'])->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.appearance.edit'))->assertOk()->assertSee('Ek CSS');

        // The HTML fields run on every page: changing them asks for the password again. CSS alone does not.
        $this->post(route('admin.appearance.extras'), ['custom_css' => '.x {}', 'head_html' => '<script>1</script>'])->assertSessionHasErrors('current_password');
        $this->assertSame('', (string) setting('theme.head_html'));
        $this->post(route('admin.appearance.extras'), ['custom_css' => '.x {}'])->assertSessionHasNoErrors();

        $this->post(route('admin.appearance.extras'), [
            'custom_css' => ".brand { color: red; }\n</style><script>alert(1)</script>",
            'head_html' => '<meta name="verify" content="abc123">',
            'footer_html' => '<!-- izleme kodu -->',
            'logo' => UploadedFile::fake()->image('logo.png', 300, 80),
            'current_password' => 'password',
        ])->assertRedirect();

        $page = $this->get('/')->assertOk()->getContent();
        // The CSS cannot close its own <style> element.
        $this->assertStringContainsString('<style id="custom-css">.brand { color: red; }', $page);
        $this->assertStringNotContainsString('</style><script>alert(1)', $page);
        $this->assertStringContainsString('<meta name="verify" content="abc123">', $page);
        $this->assertStringContainsString('<!-- izleme kodu -->', $page);
        $this->assertStringContainsString('class="brand-logo"', $page);
        Storage::disk('public')->assertExists(setting('theme.logo_path'));

        // Safe mode ignores all of it.
        config(['forum.safe_mode' => true]);
        $safe = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('custom-css', $safe);
        $this->assertStringNotContainsString('name="verify"', $safe);
        config(['forum.safe_mode' => false]);

        $old = setting('theme.logo_path');
        $this->post(route('admin.appearance.extras'), ['remove_logo' => '1'])->assertRedirect();
        Storage::disk('public')->assertMissing($old);
        $this->get('/')->assertOk()->assertDontSee('brand-logo', false)->assertDontSee('custom-css', false);
    }

    public function test_phrases_can_be_changed_and_reset(): void
    {
        $this->actingAs($this->admin);
        $this->get(route('admin.phrases', ['q' => 'Yeni neler var']))->assertOk()->assertSee('1 sonuç');
        $this->get('/')->assertOk()->assertSee('Yeni neler var');

        $this->post(route('admin.phrases.save'), ['locale' => 'tr', 'key' => "What's new", 'value' => 'Güncel konular'])->assertRedirect();
        app('translator')->setLoaded([]);
        $this->get('/')->assertOk()->assertSee('Güncel konular')->assertDontSee('Yeni neler var');
        $this->get(route('admin.phrases', ['changed' => 1]))->assertOk()->assertSee('Güncel konular')->assertSee('1 sonuç');

        // Placeholders keep working in a changed text.
        $this->post(route('admin.phrases.save'), ['locale' => 'tr', 'key' => 'Welcome aboard, :name!', 'value' => 'Hoş geldin :name, iyi forumlar!'])->assertRedirect();
        app('translator')->setLoaded([]);
        $this->assertSame('Hoş geldin Ayşe, iyi forumlar!', __('Welcome aboard, :name!', ['name' => 'Ayşe']));

        $this->post(route('admin.phrases.save'), ['locale' => 'tr', 'key' => "What's new", 'reset' => '1'])->assertRedirect();
        app('translator')->setLoaded([]);
        $this->get('/')->assertOk()->assertSee('Yeni neler var');

        $this->actingAs($this->member)->post(route('admin.phrases.save'), ['locale' => 'tr', 'key' => 'Forums', 'value' => 'x'])->assertForbidden();
    }

    public function test_templates_are_edited_as_copies_and_broken_ones_are_refused(): void
    {
        $customizer = app(Customizer::class);
        $file = 'errors/404.blade.php';
        $original = File::get(resource_path('views/'.$file));

        $this->actingAs($this->member)->get(route('admin.templates'))->assertForbidden();
        $this->actingAs($this->admin);
        $this->get(route('admin.templates', ['q' => 'errors']))->assertOk()->assertSee($file);
        $this->get(route('admin.templates.edit', ['file' => $file]))->assertOk();
        $this->get(route('admin.templates.edit', ['file' => '../../.env']))->assertNotFound();

        $save = fn (array $data) => $this->post(route('admin.templates.save'), $data + ['file' => $file, 'current_password' => 'password']);

        $this->post(route('admin.templates.save'), ['file' => $file, 'content' => 'x', 'current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $save(['file' => '../../.env', 'content' => 'APP_KEY='])->assertNotFound();
        // A template that does not compile is never written.
        $save(['content' => '@if ($broken'."\n<p>eksik</p>"])->assertSessionHas('error');
        $save(['content' => '<?php if (: ?>'])->assertSessionHas('error');
        $this->assertFalse($customizer->isOverridden($file));

        $save(['content' => str_replace('</h1>', ' (özel)</h1>', $original).'<p>Değiştirilmiş şablon</p>'])->assertRedirect();
        $this->assertTrue($customizer->isOverridden($file));
        $this->assertSame($original, File::get(resource_path('views/'.$file)));   // the package file is untouched
        $this->assertStringContainsString('Değiştirilmiş şablon', $customizer->source($file));
        $this->get(route('admin.templates'))->assertOk()->assertSee('Değiştirdiğiniz şablonlar');

        $save(['content' => 'x', 'revert' => '1'])->assertRedirect(route('admin.templates'));
        $this->assertFalse($customizer->isOverridden($file));
    }

    public function test_addons_are_discovered_switched_on_and_off(): void
    {
        $this->writeAddon('Acme/Hello', 'Acme\\Hello', <<<'PHP'
<?php

namespace Acme\Hello;

use App\Forum\Addons\Addon as BaseAddon;
use Illuminate\Support\Facades\Route;

class Addon extends BaseAddon
{
    public static array $threads = [];

    public function boot(): void
    {
        $this->loadViews();
        $this->hook('layout.footer', fn () => '<span id="acme-hello">'.e('Acme <eklentisi>').'</span>');
        $this->hook('thread.after_first_post', fn (array $context) => '<div id="acme-thread">'.e($context['thread']->title).'</div>');
        $this->on('forum.thread.created', function ($thread) { self::$threads[] = $thread->title; });
        Route::middleware('web')->get('/acme-hello', fn () => view('acme-hello::page', ['who' => auth()->user()?->username ?? 'misafir']));
    }

    public function install(): void
    {
        setting('board.name'); // the application is fully available here
        file_put_contents($this->path('installed.txt'), 'yes');
    }
}
PHP, ['views/page.blade.php' => '<p>Merhaba {{ $who }}</p>']);
        $this->writeAddon('Acme/Broken', 'Acme\\Broken', "<?php\n\nnamespace Acme\\Broken;\n\nclass Addon extends \\App\\Forum\\Addons\\Addon\n{\n    public function boot(): void\n    {\n        throw new \\RuntimeException('bozuk eklenti');\n    }\n}\n");

        $manager = app(Manager::class);
        $this->assertSame(['Acme/Broken', 'Acme/Hello'], array_keys($manager->all()));

        $this->actingAs($this->member)->get(route('admin.addons'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.addons'))->assertOk()->assertSee('Acme/Hello')->assertSee('Kapalı');
        $this->get('/')->assertOk()->assertDontSee('acme-hello', false);

        // A broken add-on reports its error and stays off; the forum keeps working.
        $this->post(route('admin.addons.toggle'), ['id' => 'Acme/Broken', 'action' => 'enable'])->assertSessionHas('error');
        $this->assertFalse($manager->isEnabled('Acme/Broken'));
        $this->post(route('admin.addons.toggle'), ['id' => 'No/Such', 'action' => 'enable'])->assertSessionHasErrors('id');

        $this->post(route('admin.addons.toggle'), ['id' => 'Acme/Hello', 'action' => 'enable'])->assertSessionHas('success');
        $this->assertTrue($manager->isEnabled('Acme/Hello'));
        $this->assertFileExists($this->addonRoot.'/Acme/Hello/installed.txt');

        // Hooks print in the layout, the add-on's route and view work, forum events reach it.
        $this->get('/')->assertOk()->assertSee('<span id="acme-hello">Acme &lt;eklentisi&gt;</span>', false);
        $this->get('/acme-hello')->assertOk()->assertSee('Merhaba '.$this->admin->username);
        $thread = app(Posting::class)->createThread(Node::where('title', 'Genel Sohbet')->firstOrFail(), $this->member, 'Eklenti görsün', 'Metin');
        $this->assertSame(['Eklenti görsün'], Addon::$threads);
        $this->get(route('threads.show', $thread))->assertOk()->assertSee('<div id="acme-thread">Eklenti görsün</div>', false);

        // An add-on that is on cannot be deleted; off, its files can go.
        $this->post(route('admin.addons.toggle'), ['id' => 'Acme/Hello', 'action' => 'delete'])->assertSessionHas('error');
        $this->post(route('admin.addons.toggle'), ['id' => 'Acme/Hello', 'action' => 'disable'])->assertSessionHas('success');
        $this->assertSame([], app(Settings::class)->get('addons.enabled'));
        $this->post(route('admin.addons.toggle'), ['id' => 'Acme/Broken', 'action' => 'delete'])->assertSessionHas('success');
        $this->assertDirectoryDoesNotExist($this->addonRoot.'/Acme/Broken');
    }

    public function test_addon_upload_and_the_bundled_example(): void
    {
        // The example that ships with the package is valid.
        config(['forum.addons_path' => null]);
        $bundled = (new Manager(app(Settings::class)))->all();
        $this->assertArrayHasKey('Meydan/Example', $bundled);
        $this->assertFileExists($bundled['Meydan/Example']['path'].'/src/Addon.php');
        config(['forum.addons_path' => $this->addonRoot]);

        $zipPath = storage_path('framework/testing/addon-'.bin2hex(random_bytes(4)).'.zip');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('my-addon/addon.json', json_encode(['id' => 'Acme/Zipped', 'title' => 'Zipli eklenti', 'namespace' => 'Acme\\Zipped']));
        $zip->addFromString('my-addon/src/Addon.php', "<?php\n\nnamespace Acme\\Zipped;\n\nclass Addon extends \\App\\Forum\\Addons\\Addon\n{\n    public function boot(): void {}\n}\n");
        $zip->addFromString('my-addon/../../evil.php', '<?php // must never be written');
        $zip->close();

        $upload = fn (string $password) => $this->post(route('admin.addons.upload'), [
            'package' => new UploadedFile($zipPath, 'addon.zip', 'application/zip', null, true), 'current_password' => $password,
        ]);

        $this->actingAs($this->member);
        $upload('password')->assertForbidden();
        $this->actingAs($this->admin);
        $upload('wrong')->assertSessionHasErrors('current_password');
        $upload('password')->assertSessionHas('success');

        $this->assertFileExists($this->addonRoot.'/Acme/Zipped/src/Addon.php');
        $this->assertFileDoesNotExist(dirname($this->addonRoot).'/evil.php');
        $this->assertFileDoesNotExist($this->addonRoot.'/evil.php');
        $this->get(route('admin.addons'))->assertOk()->assertSee('Zipli eklenti');

        // Not an add-on.
        $bad = new ZipArchive;
        $bad->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $bad->addFromString('readme.txt', 'merhaba');
        $bad->close();
        $upload('password')->assertSessionHas('error');

        @unlink($zipPath);
    }

    public function test_hooks_survive_a_failing_listener(): void
    {
        $hooks = new Hooks;
        $hooks->listen('place', fn () => 'bir');
        $hooks->listen('place', fn () => throw new \RuntimeException('patladı'));
        $hooks->listen('place', fn (array $context) => ' iki '.$context['n']);

        $this->assertSame('bir iki 3', $hooks->render('place', ['n' => 3]));
        $this->assertSame('', $hooks->render('nobody-listens'));
        $hooks->listen('double', fn (int $value) => $value * 2);
        $this->assertSame(8, $hooks->filter('double', 4));
    }

    /** @param array<string, string> $extra relative path => contents */
    protected function writeAddon(string $id, string $namespace, string $class, array $extra = []): void
    {
        $path = $this->addonRoot.'/'.$id;
        File::ensureDirectoryExists($path.'/src');
        File::put($path.'/addon.json', json_encode(['id' => $id, 'title' => $id, 'version' => '0.1.0', 'namespace' => $namespace]));
        File::put($path.'/src/Addon.php', $class);
        foreach ($extra as $relative => $contents) {
            File::ensureDirectoryExists(dirname($path.'/'.$relative));
            File::put($path.'/'.$relative, $contents);
        }
    }
}
