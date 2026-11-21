<?php

namespace Tests\Feature;

use App\Forum\BbCode\Renderer;
use App\Forum\Installer;
use App\Forum\Moderation\Censor;
use App\Forum\Posting;
use App\Forum\Settings;
use App\Models\Ban;
use App\Models\Node;
use App\Models\Post;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Word censor, link moderation for new members, the spam cleaner and per-forum moderators. */
class PhaseFourTest extends TestCase
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
        $this->admin = User::factory()->inGroup('admin')->create();
        $this->moderator = User::factory()->inGroup('moderator')->create();
        $this->member = User::factory()->create();
        $this->forum = Node::where('title', 'Genel Sohbet')->firstOrFail();
        app(Settings::class)->set(['posting.flood_seconds' => 0]);
    }

    public function test_censored_words_are_masked(): void
    {
        app(Settings::class)->set(['censor.words' => "salak\naptal=saygısız söz\nküf*\nx", 'censor.mask' => '[...]']);
        app(Censor::class)->flush();
        $censor = app(Censor::class);

        $this->assertSame('Sen [...] değilsin, [...]!', $censor->clean('Sen SALAK değilsin, Salak!'));
        $this->assertSame('salaklık kalır', $censor->clean('salaklık kalır'));                 // whole words only
        $this->assertSame('saygısız söz biri', $censor->clean('aptal biri'));
        $this->assertSame('[...] ve [...]', $censor->clean('küfür ve küfretmek'));
        $this->assertSame('x işareti kalır', $censor->clean('x işareti kalır'));             // one-letter entries are ignored

        $this->assertSame('Bu <strong>[...]</strong> bir söz', app(Renderer::class)->render('Bu [b]salak[/b] bir söz'));

        $this->actingAs($this->member)->post(route('threads.store', $this->forum), ['title' => 'Salak başlık', 'body' => 'Metin'])->assertRedirect();
        $this->assertSame('[...] başlık', Thread::latest('id')->value('title'));
    }

    public function test_links_from_new_members_wait_for_approval(): void
    {
        app(Settings::class)->set(['spam.link_min_posts' => 3]);
        $posting = app(Posting::class);

        $plain = $posting->createThread($this->forum, $this->member, 'Düz konu', 'Bağlantı yok');
        $this->assertSame('visible', $plain->state);

        $linked = $posting->createThread($this->forum, $this->member, 'Reklam', 'Sitem: https://spam.example');
        $this->assertSame('moderated', $linked->state);
        $this->assertSame('moderated', $posting->reply($plain, $this->member, '[url=http://x.example]tıkla[/url]')->state);
        $this->assertSame('moderated', $posting->reply($plain, $this->member, 'www.spam.example adresine bak')->state);

        // Moderators are trusted, and so is the member once they have posted enough.
        $this->assertSame('visible', $posting->reply($plain, $this->moderator, 'https://example.com')->state);
        $posting->reply($plain, $this->member, 'iki');
        $posting->reply($plain, $this->member, 'üç');
        $this->assertGreaterThanOrEqual(3, $this->member->fresh()->post_count);
        $this->assertSame('visible', $posting->reply($plain, $this->member->fresh(), 'Artık olur: https://example.com')->state);
    }

    public function test_spam_cleaner_bans_and_removes_everything(): void
    {
        $posting = app(Posting::class);
        $spammer = User::factory()->create(['last_ip' => '203.0.113.50', 'registration_ip' => '203.0.113.51', 'signature' => 'ucuz hosting', 'website' => 'https://spam.example']);
        $own = $posting->createThread($this->forum, $spammer, 'Ucuz saat', 'Spam');
        $honest = $posting->createThread($this->forum, $this->member, 'Gerçek konu', 'Metin');
        $spamReply = $posting->reply($honest, $spammer, 'Spam cevap');
        $posting->reply($own, $this->member, 'Bu ne?');

        $this->actingAs($this->member)->post(route('members.spam-clean', $spammer))->assertForbidden();
        $this->actingAs($this->moderator);
        $this->post(route('members.spam-clean', $this->admin))->assertForbidden();
        $this->get(route('members.show', $spammer))->assertOk()->assertSee('Spam temizleyici');
        $this->post(route('members.spam-clean', $spammer), ['ban_addresses' => '1'])->assertRedirect();

        $spammer->refresh();
        $this->assertTrue($spammer->isBanned());
        $this->assertNull($spammer->banned_until);
        $this->assertNull($spammer->signature);
        $this->assertSame('deleted', $own->fresh()->state);
        $this->assertSame('deleted', $spamReply->fresh()->state);
        $this->assertSame('visible', $honest->fresh()->state);
        $this->assertSame(0, $honest->fresh()->reply_count);
        $this->assertSame(1, $this->forum->fresh()->thread_count);
        $this->assertSame(0, $spammer->post_count);
        $this->assertSame(['203.0.113.50', '203.0.113.51'], Ban::where('type', 'ip')->orderBy('value')->pluck('value')->all());

        // Guests no longer see any of it; a moderator can still restore a thread.
        auth()->logout();
        $this->get(route('nodes.show', $this->forum))->assertOk()->assertDontSee('Ucuz saat')->assertSee('Gerçek konu');
        $this->get(route('threads.show', $honest))->assertOk()->assertDontSee('Spam cevap');
        $this->actingAs($this->moderator)->post(route('threads.moderate', $own), ['action' => 'restore'])->assertRedirect();
        $this->assertSame('visible', $own->fresh()->state);
    }

    public function test_forum_moderators_get_tools_only_in_their_forum(): void
    {
        $posting = app(Posting::class);
        $elsewhere = Node::where('title', 'Tanışma')->firstOrFail();
        $category = Node::create(['type' => 'category', 'title' => 'Bölge']);
        $parent = Node::create(['type' => 'forum', 'title' => 'Üst forum', 'parent_id' => $category->id]);
        $child = Node::create(['type' => 'forum', 'title' => 'Alt forum', 'parent_id' => $parent->id]);

        $inParent = $posting->createThread($parent, $this->admin, 'Üst konu', 'Metin');
        $inChild = $posting->createThread($child, $this->admin, 'Alt konu', 'Metin');
        $outside = $posting->createThread($elsewhere, $this->admin, 'Dışarıdaki konu', 'Metin');

        $this->actingAs($this->member)->post(route('threads.moderate', $inParent), ['action' => 'lock'])->assertForbidden();

        $this->actingAs($this->admin);
        $this->post(route('admin.nodes.moderators.add', $parent), ['username' => 'yok-boyle-biri'])->assertSessionHas('error');
        $this->post(route('admin.nodes.moderators.add', $parent), ['username' => $this->member->username])->assertRedirect();
        $this->get(route('admin.nodes.permissions', $parent))->assertOk()->assertSee($this->member->username);

        // Tools work in the forum and below it, nowhere else; nothing permanent, no global powers.
        $this->actingAs($this->member);
        $this->get(route('nodes.show', $parent))->assertOk()->assertSee('Moderatörler')->assertSee('thread-mod-form');
        $this->post(route('threads.moderate', $inParent), ['action' => 'lock'])->assertRedirect();
        $this->post(route('threads.moderate', $inChild), ['action' => 'stick'])->assertRedirect();
        $this->assertTrue($inParent->fresh()->is_locked && $inChild->fresh()->is_sticky);
        $this->post(route('threads.moderate', $outside), ['action' => 'lock'])->assertForbidden();
        $this->post(route('threads.moderate', $inParent), ['action' => 'hard_delete'])->assertForbidden();
        $this->get(route('mod.reports'))->assertForbidden();
        $this->get(route('admin.dashboard'))->assertForbidden();
        $this->put(route('posts.update', Post::where('thread_id', $inChild->id)->value('id')), ['title' => 'Alt konu (düzenlendi)', 'body' => 'Metin'])->assertRedirect();
        $this->assertSame('Alt konu (düzenlendi)', $inChild->fresh()->title);

        // Removed again: back to an ordinary member.
        $this->actingAs($this->admin)->delete(route('admin.nodes.moderators.remove', [$parent, $this->member]))->assertRedirect();
        $this->actingAs($this->member)->post(route('threads.moderate', $inParent), ['action' => 'unlock'])->assertForbidden();
    }
}
