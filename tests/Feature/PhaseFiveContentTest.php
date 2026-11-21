<?php

namespace Tests\Feature;

use App\Forum\Installer;
use App\Forum\Mailing;
use App\Forum\Posting;
use App\Forum\Settings;
use App\Forum\Spam\Guard;
use App\Mail\AlertMail;
use App\Models\ContactMessage;
use App\Models\Node;
use App\Models\Notice;
use App\Models\Page;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Pages, notices, menu links, contact form, sitemap, feeds, structured data, search filters, announcement mail. */
class PhaseFiveContentTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $member;

    protected Node $forum;

    protected function setUp(): void
    {
        parent::setUp();

        app(Installer::class)->installDefaults();
        $this->admin = User::factory()->inGroup('admin')->create();
        $this->member = User::factory()->create();
        $this->forum = Node::where('title', 'Genel Sohbet')->firstOrFail();
        app(Settings::class)->set(['posting.flood_seconds' => 0]);
    }

    public function test_pages_notices_and_menu_links(): void
    {
        // The starter pages are drafts: guests cannot open them and they are not linked.
        $rules = Page::where('slug', 'kurallar')->firstOrFail();
        $this->get(route('pages.show', $rules))->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee('Forum Kuralları');

        $this->actingAs($this->member)->get(route('admin.pages'))->assertForbidden();
        $this->actingAs($this->admin);
        $this->get(route('pages.show', $rules))->assertOk()->assertSee('Taslak');
        $this->get(route('admin.pages'))->assertOk()->assertSee('Forum Kuralları');
        $this->put(route('admin.pages.update', $rules), ['title' => 'Forum Kuralları', 'body' => '[b]Saygı[/b] <script>x</script>', 'is_published' => '1', 'show_in_footer' => '1'])->assertRedirect();

        $this->post(route('admin.pages.store'), ['title' => 'Hakkımızda Sayfası', 'body' => 'Biz kimiz', 'is_published' => '1', 'show_in_footer' => '1'])->assertRedirect();
        $about = Page::where('title', 'Hakkımızda Sayfası')->firstOrFail();
        $this->assertSame('hakkimizda-sayfasi', $about->slug);
        $this->post(route('admin.pages.store'), ['title' => 'Çakışma', 'slug' => 'kurallar', 'body' => 'x'])->assertSessionHasErrors('slug');

        // Notices: audience and dates decide who sees them.
        $this->post(route('admin.notices.save'), ['title' => 'Bakım', 'body' => 'Cumartesi [b]bakım[/b] var.', 'style' => 'warning', 'audience' => 'all', 'is_active' => '1', 'is_dismissible' => '1'])->assertRedirect();
        $this->post(route('admin.notices.save'), ['body' => 'Hemen üye olun!', 'style' => 'info', 'audience' => 'guests', 'is_active' => '1'])->assertRedirect();
        $this->post(route('admin.notices.save'), ['body' => 'Gelecek duyuru', 'style' => 'info', 'audience' => 'all', 'is_active' => '1', 'starts_at' => now()->addDay()->format('Y-m-d\TH:i')])->assertRedirect();

        // Menu links: only local paths or http(s) addresses.
        $this->post(route('admin.links.save'), ['title' => 'Kötü', 'url' => 'javascript:alert(1)', 'position' => 'header', 'is_active' => '1'])->assertSessionHasErrors('url');
        $this->post(route('admin.links.save'), ['title' => 'Kötü', 'url' => '//evil.example', 'position' => 'header', 'is_active' => '1'])->assertSessionHasErrors('url');
        $this->post(route('admin.links.save'), ['title' => 'Blogumuz', 'url' => 'https://blog.example.com', 'position' => 'header', 'new_tab' => '1', 'is_active' => '1'])->assertRedirect();
        $this->post(route('admin.links.save'), ['title' => 'Kurallar bağlantısı', 'url' => '/pages/kurallar', 'position' => 'footer', 'is_active' => '1'])->assertRedirect();
        $this->get(route('admin.notices'))->assertOk()->assertSee('Blogumuz');

        $member = $this->get('/')->assertOk()
            ->assertSee('Bakım')->assertSee('<strong>bakım</strong>', false)->assertDontSee('Hemen üye olun!')->assertDontSee('Gelecek duyuru')
            ->assertSee('Blogumuz')->assertSee('Kurallar bağlantısı')->assertSee(route('pages.show', $about), false);
        $this->assertMatchesRegularExpression('~href="https://blog\.example\.com"\s+target="_blank" rel="noopener"~', $member->getContent());

        auth()->logout();
        $this->get('/')->assertOk()->assertSee('Hemen üye olun!')->assertSee('Forum Kuralları');
        $this->get(route('pages.show', $rules))->assertOk()->assertSee('<strong>Saygı</strong>', false)->assertDontSee('<script>x</script>', false);

        $this->travel(2)->days();
        $this->get('/')->assertOk()->assertSee('Gelecek duyuru');

        $this->actingAs($this->admin)->delete(route('admin.notices.delete', Notice::where('title', 'Bakım')->firstOrFail()))->assertRedirect();
        $this->get('/')->assertOk()->assertDontSee('Cumartesi');
    }

    public function test_contact_form_reaches_the_admin_panel(): void
    {
        $guard = app(Guard::class);
        $this->get(route('contact'))->assertOk()->assertSee('name="form_stamp"', false);

        $form = ['name' => 'Ziyaretçi', 'email' => 'ziyaretci@example.com', 'subject' => 'Reklam hakkında', 'body' => 'Merhaba, bilgi almak istiyorum.'];
        $this->post(route('contact'), $form)->assertSessionHasErrors('captcha');

        $stamp = $guard->stamp();
        $this->travel(5)->seconds();
        $this->post(route('contact'), ['email' => 'bozuk'] + $form + ['form_stamp' => $stamp])->assertSessionHasErrors('email');
        $this->post(route('contact'), $form + ['form_stamp' => $stamp])->assertRedirect(route('contact'));
        $this->assertSame(1, ContactMessage::count());

        // A member does not have to type name and address.
        $stamp = $guard->stamp();
        $this->travel(5)->seconds();
        $this->actingAs($this->member)->post(route('contact'), ['subject' => 'Üyeden', 'body' => 'Bir önerim var, dinler misiniz?', 'form_stamp' => $stamp])->assertRedirect();
        $this->assertSame($this->member->email, ContactMessage::latest('id')->value('email'));

        $this->get(route('admin.messages'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();
        $this->get(route('admin.messages'))->assertOk()->assertSee('Reklam hakkında')->assertSee('ziyaretci@example.com');
        $this->assertSame(0, ContactMessage::where('is_read', false)->count());
    }

    public function test_sitemap_feeds_and_structured_data_only_show_what_guests_may_see(): void
    {
        $posting = app(Posting::class);
        $public = $posting->createThread($this->forum, $this->member, 'Herkese açık <konu> & başlık', 'İlk mesaj "tırnak" </script><b>x</b>');
        $private = Node::create(['type' => 'forum', 'title' => 'Gizli Forum', 'parent_id' => $this->forum->parent_id]);
        $private->permissionEntries()->create(['user_group_id' => UserGroup::system('guest')->id, 'permissions' => ['node.view' => 'never']]);
        $hidden = $posting->createThread($private, $this->member, 'Gizli konu', 'Sır');

        $sitemap = $this->get(route('sitemap'))->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        $this->assertNotFalse(simplexml_load_string($sitemap));
        $this->assertStringContainsString(route('threads.show', $public), $sitemap);
        $this->assertStringContainsString(route('nodes.show', $this->forum), $sitemap);
        $this->assertStringNotContainsString(route('threads.show', $hidden), $sitemap);
        $this->assertStringNotContainsString('gizli-forum', $sitemap);

        $feed = $this->get(route('feed'))->assertOk()->getContent();
        $xml = simplexml_load_string($feed);
        $this->assertNotFalse($xml);
        $this->assertSame('Herkese açık <konu> & başlık', (string) $xml->channel->item[0]->title);
        $this->assertStringNotContainsString('Gizli konu', $feed);
        $this->get(route('nodes.feed', $this->forum))->assertOk()->assertSee('Herkese açık', false);
        $this->get(route('nodes.feed', $private))->assertNotFound();

        // Thread page: canonical link and JSON-LD that user text cannot break out of.
        $page = $this->get(route('threads.show', $public))->assertOk()->getContent();
        $this->assertStringContainsString('<link rel="canonical" href="'.route('threads.show', $public).'">', $page);
        $this->assertSame(1, preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $page, $m));
        $data = json_decode($m[1], true);
        $this->assertSame('DiscussionForumPosting', $data['@type']);
        $this->assertSame('Herkese açık <konu> & başlık', $data['headline']);
        $this->assertStringNotContainsString('</script>', $m[1]);
        $this->assertStringContainsString('property="og:type" content="article"', $page);
        $this->assertStringContainsString('application/rss+xml', $page);
    }

    public function test_search_filters(): void
    {
        $posting = app(Posting::class);
        $other = Node::where('title', 'Tanışma')->firstOrFail();
        $first = $posting->createThread($this->forum, $this->member, 'Laravel kurulumu', 'Composer ile kurulum adımları');
        $posting->reply($first, $this->admin, 'Laravel için PHP 8.3 gerekir');
        $posting->createThread($other, $this->admin, 'Merhaba', 'Ben de Laravel kullanıyorum');

        $this->get(route('search', ['q' => 'la']))->assertOk()->assertSee('en az 3 karakter');
        $this->get(route('search', ['q' => 'Laravel']))->assertOk()->assertSee('3 sonuç');
        $this->get(route('search', ['q' => 'Laravel', 'titles' => 1]))->assertOk()->assertSee('1 sonuç')->assertSee('Composer ile kurulum');
        $this->get(route('search', ['q' => 'Laravel', 'node' => $other->id]))->assertOk()->assertSee('1 sonuç')->assertSee('Ben de Laravel');
        $this->get(route('search', ['q' => 'Laravel', 'author' => $this->admin->username]))->assertOk()->assertSee('2 sonuç');
        $this->get(route('search', ['q' => 'Laravel', 'author' => 'yok-boyle-biri']))->assertOk()->assertSee('Sonuç bulunamadı');
        // The whole category: both forums are inside it.
        $this->get(route('search', ['q' => 'Laravel', 'node' => $this->forum->parent_id]))->assertOk()->assertSee('3 sonuç');

        $this->travel(10)->days();
        $this->get(route('search', ['q' => 'Laravel', 'days' => 7]))->assertOk()->assertSee('Sonuç bulunamadı');
        $this->get(route('search', ['q' => 'Laravel', 'days' => 30, 'sort' => 'relevance']))->assertOk()->assertSee('3 sonuç');
        // A percent sign is text, not a wildcard.
        $this->get(route('search', ['q' => '%%%']))->assertOk()->assertSee('Sonuç bulunamadı');
    }

    public function test_announcement_mail_goes_to_the_right_members(): void
    {
        Mail::fake();
        $unverified = User::factory()->create(['email_verified_at' => null]);
        $optedOut = User::factory()->create(['email_announcements' => false]);
        $banned = User::factory()->create(['is_banned' => true]);

        $this->actingAs($this->admin);
        $this->get(route('admin.mail'))->assertOk()->assertSee('E-posta gönderimi kapalı');
        $this->post(route('admin.mail.send'), ['subject' => 'Duyuru', 'body' => 'Merhaba, önemli bir duyuru.'])->assertForbidden();

        app(Settings::class)->set(['mail.driver' => 'smtp', 'mail.host' => 'smtp.example.com', 'mail.from_address' => 'forum@example.com']);
        Mailing::configure(app(Settings::class));

        $body = "Merhaba, önemli bir duyuru.\n\nİkinci paragraf.";
        $this->post(route('admin.mail.send'), ['subject' => 'Duyuru', 'body' => $body, 'test' => '1'])->assertRedirect();
        Mail::assertSent(AlertMail::class, fn (AlertMail $mail) => $mail->hasTo($this->admin->email) && $mail->lines === ['Merhaba, önemli bir duyuru.', 'İkinci paragraf.']);
        Mail::assertNothingQueued();

        $this->post(route('admin.mail.send'), ['subject' => 'Duyuru', 'body' => $body])->assertRedirect(route('admin.mail'));
        Mail::assertQueued(AlertMail::class, 2);
        Mail::assertQueued(AlertMail::class, fn (AlertMail $mail) => $mail->hasTo($this->member->email));
        foreach ([$unverified, $optedOut, $banned] as $skipped) {
            Mail::assertNotQueued(AlertMail::class, fn (AlertMail $mail) => $mail->hasTo($skipped->email));
        }

        // Members switch announcements off in their preferences.
        $this->actingAs($this->member)->put(route('account.preferences'), ['theme' => 'auto', 'locale' => 'tr'])->assertRedirect();
        $this->assertFalse($this->member->fresh()->email_announcements);
    }
}
