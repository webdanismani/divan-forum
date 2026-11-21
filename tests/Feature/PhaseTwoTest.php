<?php

namespace Tests\Feature;

use App\Forum\BbCode\Renderer;
use App\Forum\Installer;
use App\Forum\Mailer;
use App\Forum\Mailing;
use App\Forum\Posting;
use App\Forum\Settings;
use App\Mail\AlertMail;
use App\Models\Bookmark;
use App\Models\Node;
use App\Models\Poll;
use App\Models\Post;
use App\Models\PostEdit;
use App\Models\Tag;
use App\Models\Thread;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Polls, tags, solutions, bookmarks, edit history, media embeds, forum watching and e-mails. */
class PhaseTwoTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $moderator;

    protected User $member;

    protected User $other;

    protected Node $forum;

    protected function setUp(): void
    {
        parent::setUp();

        app(Installer::class)->installDefaults();
        $this->admin = User::factory()->inGroup('admin')->create();
        $this->moderator = User::factory()->inGroup('moderator')->create();
        $this->member = User::factory()->create();
        $this->other = User::factory()->create();
        $this->forum = Node::where('title', 'Genel Sohbet')->firstOrFail();
        app(Settings::class)->set(['posting.flood_seconds' => 0]);
    }

    public function test_polls_are_created_voted_and_closed(): void
    {
        $form = ['title' => 'Anketli konu', 'body' => 'Hangisi?', 'poll_question' => 'En iyi editör?'];

        $this->actingAs($this->member);
        $this->post(route('threads.store', $this->forum), $form + ['poll_options' => "Yalnız bir seçenek\n\n"])->assertSessionHasErrors('poll_options');
        $this->post(route('threads.store', $this->forum), $form + ['poll_options' => "VS Code\nPhpStorm\n  Vim \nVim", 'poll_max_choices' => 2, 'poll_days' => 3])->assertRedirect();

        $thread = Thread::where('title', 'Anketli konu')->firstOrFail();
        $poll = Poll::where('thread_id', $thread->id)->firstOrFail();
        $options = $poll->options;
        $this->assertSame(['VS Code', 'PhpStorm', 'Vim'], $options->pluck('text')->all());
        $this->assertSame(2, $poll->max_choices);

        // Guests see the results but cannot vote.
        auth()->logout();
        $this->get(route('threads.show', $thread))->assertOk()->assertSee('En iyi editör?')->assertDontSee('name="options[]"', false);
        $this->post(route('polls.vote', $thread), ['options' => [$options[0]->id]])->assertRedirect(route('login'));

        $this->actingAs($this->member);
        $this->get(route('threads.show', $thread))->assertOk()->assertSee('name="options[]"', false);
        $this->post(route('polls.vote', $thread), ['options' => $options->pluck('id')->all()])->assertSessionHasErrors('poll');
        $this->post(route('polls.vote', $thread), ['options' => [999999]])->assertSessionHasErrors('poll');
        $this->post(route('polls.vote', $thread), ['options' => [$options[0]->id, $options[2]->id]])->assertRedirect();
        $this->actingAs($this->other)->post(route('polls.vote', $thread), ['options' => [$options[0]->id]])->assertRedirect();

        $this->assertSame([2, 0, 1], $poll->options()->pluck('vote_count')->all());
        $this->assertSame(2, $poll->fresh()->voter_count);
        $this->get(route('threads.show', $thread))->assertOk()->assertSee('%67')->assertSee('Oyumu değiştir');

        // Changing a vote replaces it.
        $this->post(route('polls.vote', $thread), ['options' => [$options[1]->id]])->assertRedirect();
        $this->assertSame([1, 1, 1], $poll->options()->pluck('vote_count')->all());

        // Only the starter or a moderator closes it; nobody votes afterwards.
        $this->put(route('polls.update', $thread), ['action' => 'close'])->assertForbidden();
        $this->actingAs($this->member)->put(route('polls.update', $thread), ['action' => 'delete'])->assertForbidden();
        $this->put(route('polls.update', $thread), ['action' => 'close'])->assertRedirect();
        $this->actingAs($this->other)->post(route('polls.vote', $thread), ['options' => [$options[0]->id]])->assertSessionHasErrors('poll');

        $this->actingAs($this->moderator)->put(route('polls.update', $thread), ['action' => 'delete'])->assertRedirect();
        $this->assertNull(Poll::find($poll->id));
    }

    public function test_tags_group_threads_and_respect_private_forums(): void
    {
        $this->actingAs($this->member);
        $this->post(route('threads.store', $this->forum), ['title' => 'Etiketli konu', 'body' => 'İçerik', 'tags' => 'Laravel,  PHP , laravel, <b>x</b>, a, seo, hosting, wordpress, fazla'])->assertRedirect();

        $thread = Thread::where('title', 'Etiketli konu')->firstOrFail();
        // Normalised, de-duplicated, too short ones dropped, capped at the board limit.
        $this->assertSame(['bxb', 'hosting', 'laravel', 'php', 'seo'], $thread->tags()->pluck('name')->all());
        $this->assertSame(1, Tag::where('slug', 'laravel')->value('use_count'));

        $this->get(route('threads.show', $thread))->assertOk()->assertSee(route('tags.show', 'laravel'), false);
        $this->get(route('tags.index'))->assertOk()->assertSee('laravel');
        $this->get(route('tags.show', 'laravel'))->assertOk()->assertSee('Etiketli konu');

        // Editing the first post replaces the tags; unused tags disappear.
        $this->put(route('posts.update', $thread->first_post_id), ['title' => 'Etiketli konu', 'body' => 'İçerik', 'tags' => 'php'])->assertRedirect();
        $this->assertSame(['php'], $thread->tags()->pluck('name')->all());
        $this->assertNull(Tag::where('slug', 'laravel')->first());

        // A thread in a forum guests cannot see is not listed on the tag page for them.
        $private = Node::create(['type' => 'forum', 'title' => 'Gizli', 'parent_id' => $this->forum->parent_id]);
        $private->permissionEntries()->create(['user_group_id' => UserGroup::system('guest')->id, 'permissions' => ['node.view' => 'never']]);
        $this->post(route('threads.store', $private), ['title' => 'Gizli konu', 'body' => 'Sır', 'tags' => 'php'])->assertRedirect();
        $this->get(route('tags.show', 'php'))->assertOk()->assertSee('Gizli konu');
        auth()->logout();
        $this->get(route('tags.show', 'php'))->assertOk()->assertSee('Etiketli konu')->assertDontSee('Gizli konu');

        app(Settings::class)->set(['posting.max_tags' => 0]);
        $this->get(route('tags.index'))->assertNotFound();
    }

    public function test_question_threads_bookmarks_and_edit_history(): void
    {
        $posting = app(Posting::class);
        $thread = $posting->createThread($this->forum, $this->member, 'Nasıl yapılır?', 'Soru');
        $answer = $posting->reply($thread, $this->other, 'Şöyle yapılır.');
        $second = $posting->reply($thread, $this->moderator, 'Ya da böyle.');

        // Not a question forum: no solutions.
        $this->actingAs($this->member)->post(route('posts.solution', $answer))->assertNotFound();

        $this->forum->update(['allow_solutions' => true]);
        $this->actingAs($this->other)->post(route('posts.solution', $answer))->assertForbidden();
        $this->actingAs($this->member)->post(route('posts.solution', $thread->first_post_id))->assertNotFound();
        $this->post(route('posts.solution', $answer))->assertRedirect();

        $this->assertSame($answer->id, $thread->fresh()->solution_post_id);
        $this->assertSame(1, $this->other->fresh()->solution_count);
        $this->assertSame(1, $this->other->notifications()->where('data', 'like', '%"kind":"solution"%')->count());
        $this->get(route('threads.show', $thread))->assertOk()->assertSee('is-solution', false)->assertSee('Çözüme git');
        $this->get(route('nodes.show', $this->forum))->assertOk()->assertSee('Çözüldü');

        // Another reply takes over; clicking it again clears the mark.
        $this->post(route('posts.solution', $second))->assertRedirect();
        $this->assertSame(0, $this->other->fresh()->solution_count);
        $this->assertSame(1, $this->moderator->fresh()->solution_count);
        $this->post(route('posts.solution', $second))->assertRedirect();
        $this->assertNull($thread->fresh()->solution_post_id);

        // Bookmarks are personal and toggle.
        $this->post(route('posts.bookmark', $answer), ['note' => 'Sonra bak'])->assertRedirect();
        $this->assertSame(1, Bookmark::where('user_id', $this->member->id)->count());
        $this->get(route('bookmarks.index'))->assertOk()->assertSee('Nasıl yapılır?')->assertSee('Şöyle yapılır.');
        $this->actingAs($this->other)->get(route('bookmarks.index'))->assertOk()->assertDontSee('Nasıl yapılır?');
        $this->actingAs($this->member)->post(route('posts.bookmark', $answer))->assertRedirect();
        $this->assertSame(0, Bookmark::count());

        // Every real change of the text is kept; the author and moderators can read it.
        $this->actingAs($this->other);
        $this->put(route('posts.update', $answer), ['body' => 'Şöyle yapılır.'])->assertRedirect();
        $this->assertSame(0, PostEdit::count());
        $this->put(route('posts.update', $answer), ['body' => 'Aslında şöyle yapılır.'])->assertRedirect();
        $this->assertSame('Şöyle yapılır.', PostEdit::where('post_id', $answer->id)->value('old_body'));
        $this->get(route('posts.history', $answer))->assertOk()->assertSee('Şöyle yapılır.')->assertSee('Aslında şöyle yapılır.');
        $this->actingAs($this->member)->get(route('posts.history', $answer))->assertForbidden();
        $this->actingAs($this->moderator)->get(route('posts.history', $answer))->assertOk();

        $this->post(route('preview'), ['body' => '[b]kalın[/b] <script>'])->assertOk()->assertJson(['html' => '<strong>kalın</strong> &lt;script&gt;']);
    }

    public function test_media_links_become_embeds_only_for_known_sites(): void
    {
        $renderer = app(Renderer::class);

        $html = $renderer->render("Video:\nhttps://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10\nve satır içi https://youtu.be/dQw4w9WgXcQ kalır");
        $this->assertStringContainsString('<iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"', $html);
        $this->assertSame(1, substr_count($html, '<iframe'));
        $this->assertStringContainsString('<a href="https://youtu.be/dQw4w9WgXcQ"', $html);

        $this->assertStringContainsString('player.vimeo.com/video/123456789', $renderer->render('[media]https://vimeo.com/123456789[/media]'));
        $this->assertStringContainsString('data-embed="x"', $renderer->render('[media]https://x.com/jack/status/20[/media]'));
        $this->assertStringContainsString('data-instgrm-permalink="https://www.instagram.com/p/Cabc123_-/"', $renderer->render('https://www.instagram.com/p/Cabc123_-/?igshid=1'));

        // Unknown sites and attempts to break out of the attribute stay harmless links.
        $evil = $renderer->render('[media]https://evil.example/x"onload="alert(1)[/media]');
        $this->assertStringNotContainsString('<iframe', $evil);
        $this->assertStringNotContainsString('"onload="', $evil);
        $this->assertStringNotContainsString('<iframe', $renderer->render('https://www.youtube.com.evil.example/watch?v=dQw4w9WgXcQ'));
        $this->assertStringNotContainsString('<iframe', $renderer->render("[code]\nhttps://youtu.be/dQw4w9WgXcQ\n[/code]"));

        app(Settings::class)->set(['posting.media_embeds' => false]);
        $this->assertStringNotContainsString('<iframe', app(Renderer::class)->render('https://youtu.be/dQw4w9WgXcQ'));
    }

    public function test_forum_watchers_are_alerted_and_emails_follow_preferences(): void
    {
        Mail::fake();
        app(Settings::class)->set(['mail.driver' => 'smtp', 'mail.host' => 'smtp.example.com', 'mail.from_address' => 'forum@example.com']);
        Mailing::configure(app(Settings::class));
        $posting = app(Posting::class);

        // Watching a forum.
        $this->actingAs($this->other)->post(route('nodes.watch', $this->forum))->assertRedirect();
        $this->get(route('nodes.show', $this->forum))->assertOk()->assertSee('Forum takibini bırak');

        $thread = $posting->createThread($this->forum, $this->member, 'Yeni duyuru', 'Merhaba @'.$this->moderator->username);
        $this->assertSame(1, $this->other->notifications()->where('data', 'like', '%"kind":"new_thread"%')->count());
        $this->assertSame(0, $this->member->notifications()->count());

        Mail::assertQueued(AlertMail::class, fn (AlertMail $mail) => $mail->hasTo($this->other->email) && str_contains($mail->lines[0], 'Yeni duyuru'));
        Mail::assertQueued(AlertMail::class, fn (AlertMail $mail) => $mail->hasTo($this->moderator->email));

        // Preferences switch the e-mail off, the alert itself stays.
        $this->actingAs($this->member)->put(route('account.preferences'), ['theme' => 'auto', 'locale' => 'tr', 'email_digest' => 'weekly', 'email_on_message' => '1'])->assertRedirect();
        $this->assertFalse($this->member->fresh()->email_on_reply);
        $this->assertSame('weekly', $this->member->fresh()->email_digest);

        $posting->reply($thread, $this->other, 'Cevap');
        $this->assertSame(1, $this->member->notifications()->where('data', 'like', '%"kind":"reply"%')->count());
        Mail::assertNotQueued(AlertMail::class, fn (AlertMail $mail) => $mail->hasTo($this->member->email));

        // Unconfirmed addresses never get mail; reactions are alerts only.
        $unverified = User::factory()->create(['email_verified_at' => null]);
        $this->actingAs($unverified)->post(route('nodes.watch', $this->forum));
        $posting->createThread($this->forum, $this->member, 'İkinci duyuru', 'Metin');
        Mail::assertNotQueued(AlertMail::class, fn (AlertMail $mail) => $mail->hasTo($unverified->email));

        // Weekly summary: only other people's threads count, and with nothing to report no mail is sent.
        $this->assertSame(0, app(Mailer::class)->sendDigests());
        Mail::assertNotQueued(AlertMail::class, fn (AlertMail $mail) => $mail->hasTo($this->member->email));

        $posting->createThread($this->forum, $this->other, 'Haftanın konusu', 'Metin');
        $this->travel(8)->days();
        Thread::where('title', 'Haftanın konusu')->update(['last_post_at' => now()->subDay()]);
        $this->assertSame(1, app(Mailer::class)->sendDigests());
        Mail::assertQueued(AlertMail::class, fn (AlertMail $mail) => $mail->hasTo($this->member->email) && str_contains(implode("\n", $mail->lines), 'Haftanın konusu'));

        $this->actingAs($this->other)->post(route('nodes.watch', $this->forum))->assertRedirect();
        $this->assertDatabaseMissing('node_watches', ['user_id' => $this->other->id]);
    }

    public function test_alert_mail_renders(): void
    {
        $html = (new AlertMail('Konu', 'Merhaba Ayşe,', ['İlk satır', '- [Bir konu](https://example.com/t)'], 'Aç', 'https://example.com/x'))->render();

        $this->assertStringContainsString('Merhaba Ayşe,', $html);
        $this->assertStringContainsString('https://example.com/x', $html);
        $this->assertStringContainsString('<a href="https://example.com/t"', $html);
        $this->assertStringContainsString(route('account.edit'), $html);

        // What a member typed (a thread or conversation title in an alert) stays text: no link, no picture.
        $title = '[Hesabınız askıda - doğrulayın](https://evil.example/giris) ![x](https://evil.example/p.png)';
        $html = (new AlertMail('Konu', 'Merhaba _Ayşe_,', [AlertMail::plain('ayse "'.$title.'" konuşmasında mesaj gönderdi.')], 'Aç', 'https://example.com/x'))->render();
        $this->assertStringNotContainsString('href="https://evil.example', $html);
        $this->assertStringNotContainsString('<img src="https://evil.example', $html);
        $this->assertStringContainsString('[Hesabınız askıda - doğrulayın](https://evil.example/giris)', html_entity_decode($html));
        $this->assertStringContainsString('Merhaba _Ayşe_,', $html);
    }

    public function test_new_pages_render_with_the_editor_extras(): void
    {
        $this->actingAs($this->member);

        $this->get(route('threads.create', $this->forum))->assertOk()
            ->assertSee('data-visual-toggle', false)->assertSee('data-preview-toggle', false)->assertSee('emoji-panel', false)
            ->assertSee('Anket ekle')->assertSee('name="tags"', false)->assertSee('data-draft="thread-'.$this->forum->id.'"', false);

        $thread = app(Posting::class)->createThread($this->forum, $this->other, 'Bir konu', 'Metin');
        $this->get(route('threads.show', $thread))->assertOk()->assertSee('data-multiquote', false)->assertSee('data-multiquote-insert', false)->assertSee('Yer imi');
        $this->get(route('account.edit'))->assertOk()->assertSee('E-posta bildirimleri');
        $this->actingAs($this->admin)->get(route('admin.nodes.edit', $this->forum))->assertOk()->assertSee('Soru-cevap forumu');
    }
}
