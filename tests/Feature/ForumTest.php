<?php

namespace Tests\Feature;

use App\Forum\BbCode\Renderer;
use App\Forum\Installer;
use App\Forum\Posting;
use App\Forum\Settings;
use App\Forum\Spam\Guard;
use App\Models\Conversation;
use App\Models\Node;
use App\Models\NodePermission;
use App\Models\Reaction;
use App\Models\Thread;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForumTest extends TestCase
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
    }

    protected function thread(?User $author = null, ?Node $node = null, string $title = 'Deneme konusu'): Thread
    {
        return app(Posting::class)->createThread($node ?? $this->forum, $author ?? $this->member, $title, 'İlk mesaj [b]kalın[/b]');
    }

    public function test_guest_can_browse_the_board(): void
    {
        $thread = $this->thread();

        $this->get('/')->assertOk()->assertSee('Genel Sohbet')->assertSee('Deneme konusu');
        $this->get(route('nodes.show', $this->forum))->assertOk()->assertSee('Deneme konusu');
        $this->get(route('threads.show', $thread))->assertOk()->assertSee('<strong>kalın</strong>', false);
        $this->get(route('posts.show', $thread->first_post_id))->assertRedirect();
        $this->get(route('whats-new'))->assertOk()->assertSee('Deneme konusu');
        $this->get(route('members.index'))->assertOk();
        $this->get(route('members.show', $this->member))->assertOk();
        $this->get(route('search', ['q' => 'Deneme']))->assertOk()->assertSee('Deneme konusu');
        $this->get('/no-such-page')->assertNotFound();
    }

    public function test_guest_is_sent_to_login_when_posting(): void
    {
        $thread = $this->thread();

        $this->get(route('threads.create', $this->forum))->assertRedirect(route('login'));
        $this->post(route('posts.store', $thread), ['body' => 'Merhaba'])->assertRedirect(route('login'));
    }

    public function test_registration_and_login(): void
    {
        $this->get(route('register'))->assertOk();
        $form = [
            'username' => 'yeni_uye', 'email' => 'yeni@example.com',
            'password' => 'gizli-sifre-123', 'password_confirmation' => 'gizli-sifre-123', 'terms' => '1',
        ];

        // Bots: no form stamp, a form sent the instant it was opened, or the hidden field filled in.
        $this->post(route('register'), $form)->assertSessionHasErrors('captcha');
        $stamp = app(Guard::class)->stamp();
        $this->post(route('register'), $form + ['form_stamp' => $stamp])->assertSessionHasErrors('captcha');
        $this->travel(5)->seconds();
        $this->post(route('register'), $form + ['form_stamp' => $stamp, Guard::HONEYPOT => 'http://spam.example'])->assertSessionHasErrors('captcha');
        $this->assertSame(0, User::where('username', 'yeni_uye')->count());

        $this->post(route('register'), $form + ['form_stamp' => $stamp])->assertRedirect(route('welcome'));

        $user = User::where('username', 'yeni_uye')->firstOrFail();
        $this->assertSame(UserGroup::system('registered')->id, $user->user_group_id);
        $this->assertAuthenticatedAs($user);

        $this->post(route('logout'))->assertRedirect();
        $this->assertGuest();

        $this->get(route('login'))->assertOk();
        $this->post(route('login'), ['login' => 'yeni_uye', 'password' => 'yanlis'])->assertSessionHasErrors('login');
        $this->post(route('login'), ['login' => 'yeni@example.com', 'password' => 'gizli-sifre-123'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_member_can_start_a_thread_and_reply_and_counters_follow(): void
    {
        app(Settings::class)->set(['posting.flood_seconds' => 0]);

        $this->actingAs($this->member)->get(route('threads.create', $this->forum))->assertOk();
        $this->actingAs($this->member)->post(route('threads.store', $this->forum), [
            'title' => 'Yeni konu başlığı', 'body' => 'Konu içeriği',
        ])->assertRedirect();

        $thread = Thread::where('title', 'Yeni konu başlığı')->firstOrFail();
        $other = User::factory()->create();
        $this->actingAs($other)->post(route('posts.store', $thread), ['body' => "@{$this->member->username} selam"])->assertRedirect();

        $thread->refresh();
        $this->assertSame(1, $thread->reply_count);
        $this->assertSame($other->id, $thread->last_post_user_id);
        $this->assertSame(1, $this->forum->fresh()->thread_count);
        $this->assertSame(2, $this->forum->fresh()->post_count);
        $this->assertSame(1, $this->member->fresh()->post_count);
        $this->assertSame(1, $other->fresh()->post_count);

        // The thread starter watches the thread and was mentioned: two alerts.
        $kinds = $this->member->notifications()->get()->pluck('data.kind')->sort()->values()->all();
        $this->assertSame(['mention', 'reply'], $kinds);

        $this->actingAs($this->member)->get(route('threads.show', $thread))->assertOk()->assertSee('selam');
        $this->assertSame(1, $this->member->unreadNotifications()->count()); // reply alert read by visiting
        $this->actingAs($this->member)->get(route('notifications.index'))->assertOk();
    }

    public function test_node_permissions_override_group_permissions(): void
    {
        $announcements = Node::where('title', 'Duyurular')->firstOrFail();

        $this->actingAs($this->member)->get(route('threads.create', $announcements))->assertForbidden();
        $this->actingAs($this->moderator)->get(route('threads.create', $announcements))->assertOk();
    }

    public function test_private_forums_are_hidden(): void
    {
        $private = Node::create(['type' => 'forum', 'title' => 'Ekip Odası', 'parent_id' => $this->forum->parent_id]);
        foreach (['guest', 'registered'] as $key) {
            NodePermission::create(['node_id' => $private->id, 'user_group_id' => UserGroup::system($key)->id, 'permissions' => ['node.view' => 'never']]);
        }
        $thread = $this->thread($this->moderator, $private, 'Gizli konu');

        $this->get('/')->assertOk()->assertDontSee('Ekip Odası')->assertDontSee('Gizli konu');
        $this->get(route('threads.show', $thread))->assertRedirect(route('login'));
        $this->actingAs($this->member)->get(route('threads.show', $thread))->assertForbidden();
        $this->actingAs($this->member)->get(route('whats-new'))->assertDontSee('Gizli konu');
        $this->actingAs($this->member)->get(route('search', ['q' => 'Gizli']))->assertDontSee('Gizli konu');
        $this->actingAs($this->moderator)->get(route('threads.show', $thread))->assertOk();
    }

    public function test_bbcode_output_is_safe(): void
    {
        $html = app(Renderer::class)->render(
            "<script>alert(1)</script> [url=javascript:alert(1)]x[/url] [img]javascript:alert(1)[/img]\n".
            "[url=https://example.com/?a=1&b=2]bağlantı[/url] [color=red;background:url(x)]c[/color]\n".
            '[quote="ayse, post: 5"]alıntı[/quote] [code]<b>[b]ham[/b]</b>[/code] https://example.org/yol.'
        );

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('href="javascript', $html);
        $this->assertStringNotContainsString('src="javascript', $html);
        $this->assertStringNotContainsString('style="color:red;', $html);
        $this->assertStringContainsString('<a href="https://example.com/?a=1&amp;b=2"', $html);
        $this->assertStringContainsString('<blockquote class="bb-quote">', $html);
        $this->assertStringContainsString('&lt;b&gt;[b]ham[/b]&lt;/b&gt;', $html);
        $this->assertStringContainsString('<a href="https://example.org/yol"', $html);
    }

    public function test_moderation_tools(): void
    {
        $thread = $this->thread();
        $reply = app(Posting::class)->reply($thread, $this->member, 'Cevap');

        // Members cannot moderate.
        $this->actingAs($this->member)->post(route('threads.moderate', $thread), ['action' => 'lock'])->assertForbidden();

        $this->actingAs($this->moderator)->post(route('threads.moderate', $thread), ['action' => 'lock'])->assertRedirect();
        $this->assertTrue($thread->fresh()->is_locked);
        $this->actingAs($this->member)->post(route('posts.store', $thread), ['body' => 'Kilitli mi?'])->assertForbidden();

        $this->actingAs($this->moderator)->delete(route('posts.destroy', $reply))->assertRedirect();
        $this->assertSame('deleted', $reply->fresh()->state);
        $this->assertSame(0, $thread->fresh()->reply_count);

        $target = Node::where('title', 'Tanışma')->firstOrFail();
        $this->actingAs($this->moderator)->post(route('threads.moderate', $thread), ['action' => 'move', 'node_id' => $target->id])->assertRedirect();
        $this->assertSame($target->id, $thread->fresh()->node_id);
        $this->assertSame(0, $this->forum->fresh()->thread_count);
        $this->assertSame(1, $target->fresh()->thread_count);

        $this->actingAs($this->moderator)->post(route('threads.moderate', $thread), ['action' => 'delete'])->assertRedirect();
        $this->assertSame(0, $target->fresh()->thread_count);
        $this->assertSame(0, $this->member->fresh()->post_count);
        auth()->logout();
        $this->get(route('threads.show', $thread))->assertNotFound();
        $this->actingAs($this->moderator)->get(route('threads.show', $thread))->assertOk();

        // Moderators cannot hard delete by default, administrators can.
        $this->actingAs($this->moderator)->post(route('threads.moderate', $thread), ['action' => 'hard_delete'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('threads.moderate', $thread), ['action' => 'hard_delete'])->assertRedirect();
        $this->assertNull(Thread::find($thread->id));
        $this->actingAs($this->admin)->get(route('admin.logs'))->assertOk()->assertSee('thread.hardDelete');
    }

    public function test_approval_queue(): void
    {
        $this->forum->update(['require_approval' => true]);
        $thread = $this->thread();

        $this->assertSame('moderated', $thread->state);
        $this->assertSame(0, $this->forum->fresh()->thread_count);
        $this->get(route('threads.show', $thread))->assertNotFound();
        $this->actingAs($this->member)->get(route('threads.show', $thread))->assertOk();
        $this->actingAs($this->member)->get(route('mod.queue'))->assertForbidden();
        $this->actingAs($this->moderator)->get(route('mod.queue'))->assertOk()->assertSee('Deneme konusu');

        $this->actingAs($this->moderator)->post(route('threads.moderate', $thread), ['action' => 'approve'])->assertRedirect();
        $this->assertSame('visible', $thread->fresh()->state);
        $this->assertSame(1, $this->forum->fresh()->thread_count);
    }

    public function test_reactions_and_reports(): void
    {
        $thread = $this->thread();
        $like = Reaction::where('score', 1)->firstOrFail();
        $post = $thread->firstPost;
        $other = User::factory()->create();

        $this->actingAs($this->member)->post(route('posts.react', $post), ['reaction_id' => $like->id])->assertForbidden(); // own post
        $this->actingAs($other)->post(route('posts.react', $post), ['reaction_id' => $like->id])->assertRedirect();
        $this->assertSame(1, $post->fresh()->reaction_score);
        $this->assertSame(1, $this->member->fresh()->reaction_score);

        $this->actingAs($other)->post(route('posts.react', $post), ['reaction_id' => $like->id])->assertRedirect(); // toggle off
        $this->assertSame(0, $post->fresh()->reaction_score);

        $this->actingAs($other)->post(route('posts.report', $post), ['reason' => 'Kurallara aykırı içerik'])->assertRedirect();
        $this->actingAs($this->member)->get(route('mod.reports'))->assertForbidden();
        $this->actingAs($this->moderator)->get(route('mod.reports'))->assertOk()->assertSee('Kurallara aykırı içerik');
        $this->actingAs($this->moderator)->get(route('threads.show', $thread))->assertOk();
    }

    public function test_post_editing_rules(): void
    {
        $thread = $this->thread();
        $post = $thread->firstPost;
        $other = User::factory()->create();

        $this->actingAs($other)->get(route('posts.edit', $post))->assertForbidden();
        $this->actingAs($this->member)->get(route('posts.edit', $post))->assertOk();
        $this->actingAs($this->member)->put(route('posts.update', $post), ['title' => 'Düzenlenmiş başlık', 'body' => 'Yeni içerik'])->assertRedirect();

        $this->assertSame('Düzenlenmiş başlık', $thread->fresh()->title);
        $this->assertSame('Yeni içerik', $post->fresh()->body);
        $this->actingAs($this->member)->get(route('posts.quote', $post))->assertOk()->assertJsonStructure(['bbcode']);
    }

    public function test_conversations_are_private(): void
    {
        $recipient = User::factory()->create();
        $outsider = User::factory()->create();

        $this->actingAs($this->member)->get(route('conversations.create'))->assertOk();
        $this->actingAs($this->member)->post(route('conversations.store'), [
            'recipients' => $recipient->username, 'title' => 'Özel konu', 'body' => 'Merhaba',
        ])->assertRedirect();

        $conversation = Conversation::firstOrFail();
        $this->assertSame(1, $recipient->unreadNotifications()->count());

        $this->actingAs($outsider)->get(route('conversations.show', $conversation))->assertNotFound();
        $this->actingAs($recipient)->get(route('conversations.index'))->assertOk()->assertSee('Özel konu');
        $this->actingAs($recipient)->get(route('conversations.show', $conversation))->assertOk()->assertSee('Merhaba');
        $this->assertSame(0, $recipient->unreadNotifications()->count());
        $this->actingAs($recipient)->post(route('conversations.reply', $conversation), ['body' => 'Selam'])->assertRedirect();
        $this->assertSame(2, $conversation->fresh()->message_count);

        $this->actingAs($this->member)->post(route('conversations.store'), [
            'recipients' => 'olmayan_uye', 'title' => 'Başlık', 'body' => 'Merhaba',
        ])->assertSessionHasErrors('recipients');
    }

    public function test_account_pages(): void
    {
        $this->actingAs($this->member)->get(route('account.edit'))->assertOk();
        $this->actingAs($this->member)->put(route('account.profile'), [
            'location' => 'İstanbul', 'website' => 'https://example.com', 'about' => 'Merhaba', 'signature' => '[b]imza[/b]',
        ])->assertRedirect();

        $this->member->refresh();
        $this->assertSame('İstanbul', $this->member->location);
        $this->assertSame('<strong>imza</strong>', $this->member->signature_html);

        $this->actingAs($this->member)->put(route('account.profile'), ['website' => 'javascript:alert(1)'])->assertSessionHasErrors('website');
        $this->actingAs($this->member)->put(route('account.security'), ['current_password' => 'yanlis', 'email' => 'a@example.com'])->assertSessionHasErrors('current_password');
    }

    public function test_banned_members_are_locked_out(): void
    {
        $this->member->update(['is_banned' => true, 'ban_reason' => 'Spam']);

        $this->actingAs($this->member)->get('/')->assertForbidden()->assertSee('Spam');
    }

    public function test_admin_panel_is_restricted_and_renders(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->actingAs($this->member)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($this->moderator)->get(route('admin.dashboard'))->assertForbidden();

        $group = UserGroup::system('registered');
        $pages = [
            route('admin.dashboard'), route('admin.nodes.index'), route('admin.nodes.create'),
            route('admin.nodes.edit', $this->forum), route('admin.nodes.permissions', $this->forum),
            route('admin.groups.index'), route('admin.groups.create'), route('admin.groups.edit', $group),
            route('admin.users.index'), route('admin.users.edit', $this->member), route('admin.settings.edit'),
            route('admin.prefixes'), route('admin.reactions'), route('admin.logs'), route('admin.license'),
        ];
        foreach ($pages as $page) {
            $this->actingAs($this->admin)->get($page)->assertOk();
        }
    }

    public function test_admin_can_manage_structure_groups_and_settings(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('admin.nodes.store'), ['title' => 'Yeni Forum', 'type' => 'forum', 'parent_id' => $this->forum->parent_id, 'is_active' => '1'])->assertRedirect();
        $node = Node::where('title', 'Yeni Forum')->firstOrFail();
        $this->assertSame('yeni-forum', $node->slug);

        $this->put(route('admin.nodes.update', $node), ['title' => 'Yeni Forum', 'type' => 'forum', 'parent_id' => $node->id])->assertSessionHasErrors('parent_id');

        $registered = UserGroup::system('registered');
        $this->put(route('admin.nodes.permissions.update', $node), ['perms' => [$registered->id => ['thread.create' => 'never', 'bogus' => 'allow']]])->assertRedirect();
        $this->assertSame(['thread.create' => 'never'], NodePermission::where('node_id', $node->id)->firstOrFail()->permissions);

        $this->post(route('admin.groups.store'), ['name' => 'VIP', 'color' => '#aa33cc', 'perms' => ['node.view' => 'allow', 'x' => 'allow']])->assertRedirect();
        $this->assertSame(['node.view' => 'allow'], UserGroup::where('name', 'VIP')->firstOrFail()->permissions);
        $this->delete(route('admin.groups.destroy', $registered))->assertSessionHas('error');

        // The form always sends every option; unticked checkboxes are simply absent.
        $defaults = collect(app(Settings::class)->definitions())
            ->reject(fn (array $def) => $def['type'] === 'bool')
            ->mapWithKeys(fn (array $def, string $key) => [str_replace('.', '__', $key) => $def['default']])
            ->all();

        $this->put(route('admin.settings.update'), [
            'board__name' => 'Test Panosu', 'board__description' => 'Açıklama', 'board__footer_text' => '',
            'board__locale' => 'en', 'board__default_theme' => 'dark', 'board__threads_per_page' => 10,
            'board__posts_per_page' => 10, 'posting__flood_seconds' => 0, 'posting__edit_minutes' => 0,
        ] + $defaults)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('Test Panosu', setting('board.name'));
        $this->assertFalse(setting('registration.enabled'));
        $this->get('/')->assertOk()->assertSee('Test Panosu')->assertSee('Members online');

        $this->put(route('admin.users.update', $this->member), [
            'username' => $this->member->username, 'email' => $this->member->email,
            'user_group_id' => UserGroup::system('moderator')->id, 'is_banned' => '1', 'ban_reason' => 'Test',
        ])->assertRedirect();
        $this->assertTrue($this->member->fresh()->isBanned());
        $this->assertSame(UserGroup::system('moderator')->id, $this->member->fresh()->user_group_id);

        $this->delete(route('admin.nodes.destroy', $node))->assertRedirect();
        $this->assertNull(Node::find($node->id));
    }

    public function test_admin_can_change_the_board_colours(): void
    {
        $this->actingAs($this->member)->get(route('admin.appearance.edit'))->assertForbidden();
        $this->actingAs($this->member)->put(route('admin.appearance.update'), [])->assertForbidden();

        $this->actingAs($this->admin)->get(route('admin.appearance.edit'))->assertOk();
        $this->get('/')->assertOk()->assertDontSee('id="theme-colors"', false);

        $colors = ['accent' => '#FF0000', 'header' => '#ffffff', 'navbar' => '#101010', 'bar' => '#202020', 'category' => '#303030', 'background' => '#fafafa'];

        // Anything that is not a plain #rrggbb value is rejected, so nothing can be injected into the page.
        $this->put(route('admin.appearance.update'), ['colors' => ['accent' => 'red;}</style><script>alert(1)</script>'] + $colors])
            ->assertSessionHasErrors('colors.accent');
        $this->put(route('admin.appearance.update'), ['colors' => ['accent' => '#f00'] + $colors])->assertSessionHasErrors('colors.accent');

        $this->put(route('admin.appearance.update'), ['colors' => $colors])->assertRedirect();

        // Light header gets dark text, dark bars get white text. The admin panel keeps its own colours.
        $this->get('/')->assertOk()
            ->assertSee('id="theme-colors"', false)
            ->assertSee('--accent:#ff0000;', false)
            ->assertSee('--header:#ffffff;--on-header:#1f2733;', false)
            ->assertSee('--bar:#202020;--bar-text:#ffffff;', false)
            ->assertSee('--bg:#fafafa;', false);
        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('--accent:#ff0000;', false)->assertSee('assets/admin.css', false);
        $this->get(route('admin.appearance.edit'))->assertOk()->assertSee('value="#ff0000"', false);

        $this->delete(route('admin.appearance.reset'))->assertRedirect();
        $this->get('/')->assertOk()->assertDontSee('id="theme-colors"', false);
    }
}
