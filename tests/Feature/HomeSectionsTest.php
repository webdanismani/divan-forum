<?php

namespace Tests\Feature;

use App\Forum\Home;
use App\Forum\Installer;
use App\Forum\Posting;
use App\Forum\Settings;
use App\Models\Node;
use App\Models\NodePermission;
use App\Models\ThreadPrefix;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Sections of the home page (Admin > Home page): hero, tiles, news cards, side boxes, band, footer extras. */
class HomeSectionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Node $forum;

    protected function setUp(): void
    {
        parent::setUp();

        app(Installer::class)->installDefaults();
        $this->admin = User::factory()->inGroup('admin')->create();
        $this->forum = Node::where('title', 'Genel Sohbet')->firstOrFail();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_without_new_sections_the_home_page_is_the_classic_index(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('class="navbar"', false)->assertSee('latest-grid', false)->assertSee('stats-row', false)
            ->assertSee('data-welcome', false)
            ->assertDontSee('class="hero"', false)->assertDontSee('quick-tiles', false)->assertDontSee('home-news', false)
            ->assertDontSee('stat-band', false)->assertDontSee('side-online', false)->assertDontSee('footer-signup', false)
            ->assertDontSee('data-header', false);

        $this->assertFalse(app(Home::class)->any());
        $this->assertTrue(app(Home::class)->enabled('latest'));
    }

    public function test_an_administrator_fills_the_sections_and_the_home_page_shows_them(): void
    {
        Storage::fake('public');
        $member = User::factory()->create(['username' => 'oyuncu', 'last_seen_at' => now()]);
        $prefix = ThreadPrefix::create(['title' => 'Haber', 'color' => 'blue']);
        $thread = app(Posting::class)->createThread($this->forum, $member, 'Yeni oyun duyuruldu', "[img]https://example.com/kapak.jpg[/img]\n\nAyrıntılar içeride.", $prefix->id);
        $thread->update(['view_count' => 48700]);

        $this->actingAs($this->admin)->get(route('admin.home.edit'))->assertOk()->assertSee('name="slides[0][title]"', false)->assertSee('name="social[youtube]"', false);

        $this->put(route('admin.home.update'), [
            'sections' => ['hero' => 1, 'tiles' => 1, 'news' => 1, 'online' => 1, 'promos' => 1, 'popular' => 1, 'social' => 1, 'band' => 1, 'signup' => 1],
            'slides' => [
                ['title' => 'Oyun Dünyasının|Buluşma Noktası', 'mark' => 'Buluşma', 'badge' => 'En aktif forum', 'text' => 'Aramıza katılın!', 'b1_label' => 'Foruma Katıl', 'b1_link' => '/register', 'b2_label' => 'Kötü bağlantı', 'b2_link' => 'javascript:alert(1)'],
                ['title' => 'İkinci slayt', 'mark' => '', 'badge' => '', 'text' => '', 'b1_label' => '', 'b1_link' => '', 'b2_label' => '', 'b2_link' => ''],
                ['title' => '', 'text' => 'başlıksız slayt kaydedilmez'],
            ],
            'slide_image' => [0 => UploadedFile::fake()->image('hero.jpg', 1200, 400)],
            'tiles' => [['icon' => 'gamepad', 'title' => 'Yeni Oyunlar', 'text' => 'Keşfet', 'link' => '/whats-new'], ['icon' => 'trophy', 'title' => '', 'text' => '', 'link' => '']],
            'promos' => [['title' => 'Premium Üyelik', 'text' => 'Ayrıcalıklar', 'button' => 'Premium Ol', 'link' => '/upgrades', 'style' => 'gold']],
            'news' => ['node_id' => $this->forum->id, 'title' => 'Oyun Haberleri', 'count' => 4],
            'social' => ['youtube' => 'https://youtube.com/@forum', 'discord' => ''],
        ])->assertRedirect()->assertSessionHas('success');

        $home = app(Home::class);
        $this->assertCount(2, $home->slides());
        $this->assertCount(1, $home->tiles());
        Storage::disk('public')->assertExists($home->slides()[0]['image']);
        $this->assertFalse($home->enabled('latest'), 'A box left unticked is switched off.');

        auth()->logout();
        $page = $this->get('/')->assertOk()
            ->assertSee('class="hero"', false)->assertSee('Oyun Dünyasının<br><em>Buluşma</em> Noktası', false)
            ->assertSee('En aktif forum')->assertSee('href="'.url('/register').'"', false)
            ->assertDontSee('javascript:alert', false)->assertDontSee('Kötü bağlantı')
            ->assertSee('data-hero-dot="1"', false)
            ->assertSee('quick-tile qt-1', false)->assertSee('Keşfet')
            ->assertSee('home-news', false)->assertSee('Oyun Haberleri')->assertSee('Yeni oyun duyuruldu')
            ->assertSee('src="https://example.com/kapak.jpg"', false)->assertSee('48.7K')
            ->assertSee('side-online', false)->assertSee('promo promo-gold', false)->assertSee('Premium Ol')
            ->assertSee('side-popular', false)->assertSee('s-youtube', false)->assertDontSee('s-discord', false)
            ->assertSee('stat-band', false)->assertSee('footer-signup', false)
            // The hero replaces the welcome area; the classic parts were switched off in the same form.
            ->assertDontSee('data-welcome', false)->assertDontSee('latest-grid', false)->assertDontSee('stats-row', false);
        $this->assertStringContainsString('--hero-image: url(', $page->getContent());

        // A forum page has none of it.
        $this->get(route('nodes.show', $this->forum))->assertOk()->assertDontSee('class="hero"', false)->assertDontSee('stat-band', false);

        // Removing the picture deletes the upload.
        $stored = $home->slides()[0]['image'];
        $this->actingAs($this->admin)->put(route('admin.home.update'), [
            'sections' => ['hero' => 1, 'latest' => 1],
            'slides' => [['title' => 'Tek slayt', 'image' => $stored, 'remove_image' => 1]],
        ])->assertRedirect();
        Storage::disk('public')->assertMissing($stored);
        $this->assertSame('', app(Home::class)->slides()[0]['image']);
        auth()->logout();
        $this->get('/')->assertOk()->assertSee('Tek slayt')->assertDontSee('data-hero-dot', false)->assertSee('latest-grid', false)->assertDontSee('quick-tiles', false);
    }

    public function test_news_cards_only_show_threads_the_visitor_may_see(): void
    {
        $member = User::factory()->create();
        $hidden = Node::create(['type' => 'forum', 'title' => 'Ekip Odası', 'display_order' => 500, 'is_active' => true]);
        NodePermission::create(['node_id' => $hidden->id, 'user_group_id' => UserGroup::system('guest')->id, 'permissions' => ['node.view' => 'never']]);
        app(\App\Forum\Permissions\PermissionResolver::class)->flush();
        app(Posting::class)->createThread($hidden, $this->admin, 'Gizli haber', 'Sadece ekip görür.');
        app(Posting::class)->createThread($this->forum, $member, 'Açık haber', 'Herkes görür.');

        app(Home::class)->saveSections(['news' => true, 'latest' => false]);

        $this->get('/')->assertOk()->assertSee('home-news', false)->assertSee('Açık haber')->assertDontSee('Gizli haber');
        $this->actingAs($this->admin)->get('/')->assertOk()->assertSee('Gizli haber');
    }

    public function test_a_forum_can_have_a_standard_icon_and_a_colour(): void
    {
        $this->actingAs($this->admin)->put(route('admin.nodes.update', $this->forum), [
            'title' => $this->forum->title, 'type' => 'forum', 'parent_id' => $this->forum->parent_id,
            'glyph' => 'gamepad', 'color' => '#7C5CFF', 'color_on' => 1, 'is_active' => 1,
        ])->assertRedirect();

        $this->assertSame('gamepad', $this->forum->fresh()->icon);
        $this->assertSame('#7c5cff', $this->forum->fresh()->color);
        $this->get('/')->assertOk()->assertSee('has-color', false)->assertSee('--node-color: #7c5cff', false)->assertSee('data-short=', false);

        // Unticked, the colour is dropped; an icon that is not on the list is refused.
        $this->put(route('admin.nodes.update', $this->forum), ['title' => $this->forum->title, 'type' => 'forum', 'parent_id' => $this->forum->parent_id, 'glyph' => '', 'color' => '#7c5cff', 'is_active' => 1])->assertRedirect();
        $this->assertNull($this->forum->fresh()->color);
        $this->assertNull($this->forum->fresh()->icon);
        $this->put(route('admin.nodes.update', $this->forum), ['title' => $this->forum->title, 'type' => 'forum', 'glyph' => 'x" onload="1'])->assertSessionHasErrors('glyph');
    }

    public function test_the_footer_box_carries_the_address_to_the_registration_form(): void
    {
        app(Home::class)->saveSections(['signup' => true, 'latest' => true]);

        $this->get('/')->assertOk()->assertSee('footer-signup', false)->assertSee('action="'.route('register').'"', false);
        $this->get(route('register', ['email' => 'yeni@example.com']))->assertOk()->assertSee('value="yeni@example.com"', false);
        // Members are not asked to register.
        $this->actingAs($this->admin)->get('/')->assertOk()->assertDontSee('footer-signup', false);
    }

    public function test_links_headlines_and_short_numbers(): void
    {
        $home = app(Home::class);

        $this->assertSame(url('/whats-new'), $home->link('/whats-new'));
        $this->assertSame('https://example.com/a', $home->link('https://example.com/a'));
        $this->assertSame('', $home->link('javascript:alert(1)'));
        $this->assertSame('', $home->link('//evil.example'));

        $this->assertSame('A &lt;b&gt;<br><em>Vurgu</em> son', Home::headline('A <b>|Vurgu son', 'vurgu'));

        $this->assertSame('999', Home::short(999));
        $this->assertSame('12K', Home::short(12000));
        $this->assertSame('245.8K', Home::short(245800));
        $this->assertSame('210.3K', Home::short(210300));
        $this->assertSame('2.4M', Home::short(2400000));
    }

    public function test_a_theme_fills_empty_slots_only(): void
    {
        $home = app(Home::class);
        app(Settings::class)->set(['home.tiles' => [['icon' => 'star', 'title' => 'Benim kutum', 'text' => '', 'link' => '']]]);

        $manifest = [
            'id' => 'Acme/Demo',
            'home' => [
                'sections' => ['hero' => true, 'latest' => false, 'no-such-section' => true],
                'slides' => [['title' => '{board} başlığı', 'mark' => '', 'image' => '../../.env'], ['title' => 'İkinci', 'image' => 'ok.webp']],
                'tiles' => [['icon' => 'gamepad', 'title' => 'Temanın kutusu']],
            ],
        ];
        $this->assertTrue($home->adopt($manifest));

        $home = app(Home::class);
        $this->assertTrue($home->enabled('hero'));
        $this->assertFalse($home->enabled('latest'));
        $this->assertTrue($home->enabled('statistics'), 'Sections the theme does not name stay as they were.');
        $this->assertSame(setting('board.name').' başlığı', $home->slides()[0]['title']);
        $this->assertSame('', $home->slides()[0]['image'], 'A picture can only be a file of the theme itself.');
        $this->assertSame('theme:Acme/Demo|ok.webp', $home->slides()[1]['image']);
        $this->assertNull($home->image($home->slides()[1]['image']), 'A theme file that does not exist gives no address.');
        $this->assertSame('Benim kutum', $home->tiles()[0]['title']);
        $this->assertSame('Acme/Demo', $home->adopted());

        $this->assertFalse($home->adopt(['id' => 'Acme/Plain']));
        // The page still renders with a slide whose picture is gone.
        $this->get('/')->assertOk()->assertSee('class="hero"', false);

        // Only an administrator reaches the page and its actions.
        $this->actingAs(User::factory()->create())->get(route('admin.home.edit'))->assertForbidden();
        $this->actingAs($this->admin)->post(route('admin.home.adopt'))->assertRedirect()->assertSessionHas('error');
    }

    public function test_switching_a_theme_off_takes_its_home_page_sections_with_it(): void
    {
        $root = storage_path('framework/testing/home-themes-'.bin2hex(random_bytes(4)));
        config(['forum.themes_path' => $root]);
        $install = function (string $name, array $extra) use ($root) {
            \Illuminate\Support\Facades\File::ensureDirectoryExists("$root/Acme/$name");
            file_put_contents("$root/Acme/$name/theme.json", json_encode(['id' => "Acme/$name", 'title' => $name, 'version' => '1.0.0', 'colors' => ['accent' => '#aa00ff']] + $extra));
        };
        $install('Portal', ['header' => 'single', 'home' => ['sections' => ['hero' => true, 'tiles' => true, 'band' => true, 'latest' => false, 'statistics' => false],
            'slides' => [['title' => 'Portal başlığı']], 'tiles' => [['icon' => 'gamepad', 'title' => 'Portal kutusu']]]]);
        $install('Plain', []);

        try {
            $themes = app(\App\Forum\Themes::class);
            $classic = fn () => $this->get('/')->assertOk()
                ->assertSee('latest-grid', false)->assertSee('stats-row', false)->assertSee('class="navbar"', false)
                ->assertDontSee('class="hero"', false)->assertDontSee('quick-tiles', false)->assertDontSee('stat-band', false)
                ->assertDontSee('data-header', false)->assertDontSee('--accent:#aa00ff', false);
            $portal = fn () => $this->get('/')->assertOk()
                ->assertSee('class="hero"', false)->assertSee('Portal başlığı')->assertSee('quick-tiles', false)->assertSee('stat-band', false)
                ->assertSee('data-header="single"', false)->assertDontSee('latest-grid', false);

            // The administrator had one extra section on before the theme came.
            app(Home::class)->saveSections(['online' => true] + array_map(fn (array $section) => $section[1], Home::SECTIONS));
            $this->get('/')->assertOk()->assertSee('side-online', false)->assertSee('latest-grid', false);

            $this->assertTrue($themes->activate('Acme/Portal'));
            $portal();

            // Back to the standard look: nothing of the theme stays behind, the earlier home page returns.
            $themes->deactivate();
            $classic()->assertSee('side-online', false);
            $this->assertNull(app(Home::class)->adopted());

            // Switched on again it is the portal again, with the texts as they were left.
            $themes->activate('Acme/Portal');
            $portal();

            // Changing to another theme does the same as switching off.
            $this->actingAs($this->admin)->post(route('admin.market.theme'), ['id' => 'Acme/Plain', 'action' => 'activate'])->assertSessionHas('success');
            $this->get('/')->assertOk()->assertSee('latest-grid', false)->assertSee('side-online', false)
                ->assertDontSee('class="hero"', false)->assertDontSee('quick-tiles', false)->assertDontSee('data-header', false);

            // A board that was left with the sections of a theme no longer in use (before this was fixed) is repaired.
            app(Settings::class)->set(['home.sections' => ['hero' => true, 'tiles' => true, 'latest' => false], 'home.adopted' => 'Acme/Portal', 'home.before' => null]);
            $themes->deactivate();
            $this->get('/')->assertOk()->assertSee('class="hero"', false);
            (require database_path('migrations/2026_11_21_000020_release_home_sections.php'))->up();
            $this->get('/')->assertOk()->assertSee('latest-grid', false)->assertDontSee('class="hero"', false)->assertDontSee('quick-tiles', false);
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($root);
        }
    }
}
