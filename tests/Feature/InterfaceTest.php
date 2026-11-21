<?php

namespace Tests\Feature;

use App\Forum\AlertPrefs;
use App\Forum\AlertText;
use App\Forum\BbCode\Renderer;
use App\Forum\Inbox;
use App\Forum\Installer;
use App\Forum\NodeTree;
use App\Forum\Permissions\PermissionResolver;
use App\Forum\Posting;
use App\Forum\Privacy;
use App\Forum\Settings;
use App\Forum\Setup\Checklist;
use App\Http\Controllers\WelcomeController;
use App\Mail\AlertMail;
use App\Models\Conversation;
use App\Models\Node;
use App\Models\NodePermission;
use App\Models\Thread;
use App\Models\User;
use App\Models\UserGroup;
use App\Notifications\ForumAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Category bars on the home page, live search suggestions, the editor toolbar. */
class InterfaceTest extends TestCase
{
    use \Tests\Concerns\FakesLicenseServer;
    use RefreshDatabase;

    protected User $member;

    protected Node $forum;

    protected function setUp(): void
    {
        parent::setUp();

        app(Installer::class)->installDefaults();
        $this->member = User::factory()->create(['username' => 'hostingci']);
        $this->forum = Node::where('title', 'Genel Sohbet')->firstOrFail();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_category_bars_show_their_description_and_forum_count(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Duyurular, tanışma ve serbest sohbet.')
            ->assertSee('Sorularınız, önerileriniz ve geri bildirimleriniz.')
            ->assertSee('3 forum')->assertSee('2 forum')
            ->assertSee('data-label-closed="Genişlet"', false)->assertSee('data-catbar', false);

        // A category without a description simply has no second line.
        Node::where('title', 'Destek')->firstOrFail()->update(['description' => null]);
        app(NodeTree::class)->flush();
        $this->get('/')->assertOk()->assertDontSee('Sorularınız, önerileriniz');
    }

    public function test_the_header_search_suggests_threads_and_members(): void
    {
        $posting = app(Posting::class);
        $posting->createThread($this->forum, $this->member, 'Hosting firması <b>önerisi</b>', 'Metin');
        $posting->createThread($this->forum, $this->member, 'Kedi maması', 'Burada hosting kelimesi yalnızca metinde geçiyor');

        $private = Node::create(['type' => 'forum', 'title' => 'Ekip Odası', 'parent_id' => $this->forum->parent_id]);
        foreach (['guest', 'registered'] as $key) {
            NodePermission::create(['node_id' => $private->id, 'user_group_id' => UserGroup::system($key)->id, 'permissions' => ['node.view' => 'never']]);
        }
        $admin = User::factory()->inGroup('admin')->create();
        $posting->createThread($private, $admin, 'Gizli hosting pazarlığı', 'Metin');

        $this->get('/')->assertOk()->assertSee('data-suggest="/search/suggest"', false);

        $data = $this->getJson(route('search.suggest', ['q' => 'hosting']))->assertOk()->json();
        $this->assertSame(['Hosting firması <b>önerisi</b>'], array_column($data['threads'], 'title'), 'Titles only, and nothing from a forum the visitor cannot see.');
        $this->assertSame(['hostingci'], array_column($data['members'], 'title'));
        $this->assertStringContainsString('Genel Sohbet', $data['threads'][0]['meta']);
        $this->assertSame(route('search', ['q' => 'hosting']), $data['all']);

        // Staff see their own forums; one letter is not a search; wildcards are plain characters.
        $this->assertCount(2, $this->actingAs($admin)->getJson(route('search.suggest', ['q' => 'hosting']))->json('threads'));
        $this->assertSame(['threads' => [], 'members' => []], $this->getJson(route('search.suggest', ['q' => 'h']))->json());
        $this->assertSame([], $this->getJson(route('search.suggest', ['q' => '%%']))->json('threads'));
    }

    public function test_sticky_threads_have_their_own_section_and_need_permission(): void
    {
        $moderator = User::factory()->inGroup('moderator')->create();
        $posting = app(Posting::class);
        $posting->createThread($this->forum, $this->member, 'Sıradan bir konu', 'Metin');

        // No sticky thread: no headings.
        $this->get(route('nodes.show', $this->forum))->assertOk()->assertDontSee('Sabit konular')->assertDontSee('Normal konular');

        // A member does not get the options, and sending the field anyway changes nothing.
        $this->actingAs($this->member)->get(route('threads.create', $this->forum))->assertOk()->assertDontSee('Konu seçenekleri');
        app(Settings::class)->set(['posting.flood_seconds' => 0]);
        $this->post(route('threads.store', $this->forum), ['title' => 'Üyenin konusu', 'body' => 'Sabitlemeyi denedim', 'sticky' => 1, 'locked' => 1])->assertRedirect();
        $own = Thread::where('title', 'Üyenin konusu')->firstOrFail();
        $this->assertFalse((bool) $own->is_sticky);
        $this->assertFalse((bool) $own->is_locked);

        // A moderator sticks (and locks) while starting the thread.
        $this->actingAs($moderator)->get(route('threads.create', $this->forum))->assertOk()->assertSee('Konu seçenekleri')->assertSee('name="sticky"', false)->assertSee('name="locked"', false);
        $this->post(route('threads.store', $this->forum), ['title' => 'Forum kuralları', 'body' => 'Lütfen okuyun', 'sticky' => 1, 'locked' => 1])->assertRedirect();
        $rules = Thread::where('title', 'Forum kuralları')->firstOrFail();
        $this->assertTrue((bool) $rules->is_sticky);
        $this->assertTrue((bool) $rules->is_locked);

        // The list shows the sticky part first, then the normal threads, each under its heading.
        $page = $this->get(route('nodes.show', $this->forum))->assertOk()->assertSee('Sabit konular')->assertSee('Normal konular')->getContent();
        $this->assertTrue(strpos($page, 'Sabit konular') < strpos($page, 'Forum kuralları'));
        $this->assertTrue(strpos($page, 'Forum kuralları') < strpos($page, 'Normal konular'));
        $this->assertTrue(strpos($page, 'Normal konular') < strpos($page, 'Sıradan bir konu'));
    }

    public function test_a_member_can_put_a_cover_photo_on_the_profile(): void
    {
        Storage::fake('public');
        $upload = fn (int $width, int $height, string $name = 'kapak.jpg') => ['cover' => UploadedFile::fake()->image($name, $width, $height)];

        $this->post(route('account.cover'), $upload(1500, 400))->assertRedirect(route('login'));
        $this->get(route('members.show', $this->member))->assertOk()->assertDontSee('profile-cover', false);

        // The owner is invited to add one; nobody else sees the empty strip.
        $this->actingAs($this->member)->get(route('members.show', $this->member))->assertOk()->assertSee('Kapak fotoğrafı ekle');
        $this->get(route('account.edit'))->assertOk()->assertSee('Kapak fotoğrafı');

        // Too small, or not a picture: refused.
        $this->post(route('account.cover'), $upload(300, 100))->assertSessionHasErrors('cover');
        $this->post(route('account.cover'), ['cover' => UploadedFile::fake()->create('kapak.php', 10, 'application/x-php')])->assertSessionHasErrors('cover');
        $this->assertNull($this->member->refresh()->cover_path);

        $this->post(route('account.cover'), $upload(1500, 400))->assertSessionHas('success');
        $first = $this->member->refresh()->cover_path;
        Storage::disk('public')->assertExists($first);
        $this->post(route('logout'));
        $this->get(route('members.show', $this->member))->assertOk()->assertSee('class="profile-cover"', false)->assertSee(basename($first));

        // A new picture replaces the old file; removing it deletes the file.
        $this->actingAs($this->member)->post(route('account.cover'), $upload(1200, 300, 'yeni.png'))->assertSessionHas('success');
        Storage::disk('public')->assertMissing($first);
        $second = $this->member->refresh()->cover_path;
        $this->delete(route('account.cover.remove'))->assertSessionHas('success');
        Storage::disk('public')->assertMissing($second);
        $this->assertNull($this->member->refresh()->cover_path);
    }

    public function test_guests_are_welcomed_and_members_are_not(): void
    {
        $this->get('/')->assertOk()->assertSee('data-welcome', false)->assertSee('hoş geldiniz')->assertSee('Ücretsiz üye ol')->assertSee('Konu açın, tartışmalara katılın');
        $this->get(route('nodes.show', $this->forum))->assertOk()->assertDontSee('data-welcome', false);
        $this->actingAs($this->member)->get('/')->assertOk()->assertDontSee('data-welcome', false);
        $this->post(route('logout'));

        // Title and text come from the settings; closed registration removes the sign-up button.
        app(Settings::class)->set(['welcome.title' => 'Webmaster <b>topluluğu</b>', 'welcome.text' => 'SEO, hosting ve yazılım üzerine.', 'registration.enabled' => false]);
        $this->get('/')->assertOk()->assertSee('Webmaster &lt;b&gt;topluluğu&lt;/b&gt;', false)->assertSee('SEO, hosting ve yazılım üzerine.')->assertDontSee('Ücretsiz üye ol');

        app(Settings::class)->set(['welcome.enabled' => false]);
        $this->get('/')->assertOk()->assertDontSee('data-welcome', false);
    }

    public function test_the_editor_offers_tabs_groups_and_menus(): void
    {
        $this->actingAs($this->member)->get(route('threads.create', $this->forum))->assertOk()
            ->assertSee('data-write-tab', false)->assertSee('data-visual-toggle', false)->assertSee('data-preview-toggle', false)
            ->assertSee('data-bb="olist"', false)->assertSee('data-bb="center"', false)->assertSee('data-bb="icode"', false)
            ->assertSee('data-open="[size=6]"', false)->assertSee('data-open="[color=#1877d4]"', false)
            ->assertSee('data-editor-count', false)->assertSee('Numaralı liste')
            // Addresses are asked for in the editor's own pop-over, not in a browser prompt.
            ->assertSee('data-l-address="Adres"', false)->assertSee('data-l-text="Görünecek metin"', false)->assertDontSee('data-prompt', false);

        // What the new buttons insert is understood by the renderer.
        $html = app(Renderer::class)->render("[size=6]Başlık[/size] [color=#1877d4]mavi[/color] [center]orta[/center] [icode]kod[/icode]\n[list=1]\n[*]bir\n[*]iki\n[/list]");
        $this->assertStringContainsString('<span class="bb-size-6">Başlık</span>', $html);
        $this->assertStringContainsString('<span style="color:#1877d4">mavi</span>', $html);
        $this->assertStringContainsString('<div class="bb-center">orta</div>', $html);
        $this->assertStringContainsString('<code class="bb-icode">kod</code>', $html);
        $this->assertStringContainsString('<ol class="bb-list"><li>bir</li><li>iki</li></ol>', $html);
    }

    /** A group the board could sell: its members get the extra privacy and signature choices. */
    protected function premium(User $user): void
    {
        $group = UserGroup::create(['name' => 'Premium', 'priority' => 20, 'permissions' => [
            'privacy.contact' => 'allow', 'privacy.online' => 'allow', 'signature.links' => 'allow', 'signature.images' => 'allow', 'signature.long' => 'allow',
        ]]);
        $user->groups()->attach($group->id);
        $user->unsetRelation('groups');
        app(PermissionResolver::class)->flush();
    }

    public function test_members_add_each_other_as_friends(): void
    {
        $other = User::factory()->create(['username' => 'seocu']);
        $friend = fn (User $who, string $action = 'add') => $this->post(route('members.friend', $who), ['action' => $action]);

        $friend($other)->assertRedirect(route('login'));
        $this->actingAs($this->member)->get(route('members.show', $other))->assertOk()->assertSee('Arkadaş ekle');
        $friend($this->member)->assertStatus(422);

        // The request waits for an answer and the other member hears about it.
        $friend($other)->assertSessionHas('success');
        $this->assertDatabaseHas('friendships', ['user_id' => $this->member->id, 'friend_id' => $other->id, 'status' => 'pending']);
        $this->assertSame('friend_request', $other->notifications()->first()->data['kind']);
        $this->get(route('members.show', $other))->assertOk()->assertSee('İstek gönderildi')->assertDontSee('Arkadaş ekle');
        $this->get(route('friends.index'))->assertOk()->assertSee('Gönderdiğiniz istekler')->assertSee('seocu');
        $friend($other)->assertSessionHas('success');
        $this->assertSame(1, DB::table('friendships')->count(), 'Asking twice does not add a second request.');

        // Accepting makes both sides friends.
        $this->actingAs($other)->get(route('friends.index'))->assertOk()->assertSee('Arkadaşlık istekleri')->assertSee('hostingci');
        $this->get(route('members.show', $this->member))->assertOk()->assertSee('Arkadaşlık isteğini kabul et');
        $friend($this->member)->assertSessionHas('success');
        $this->assertDatabaseHas('friendships', ['status' => 'accepted']);
        $this->assertSame('friend_accept', $this->member->notifications()->first()->data['kind']);
        $this->get(route('members.show', $this->member))->assertOk()->assertSee('friend-grid', false);
        $this->get(route('friends.index'))->assertOk()->assertSee('Arkadaşlarım')->assertSee('hostingci')->assertDontSee('Arkadaşlık istekleri');

        // Either side can end it.
        $friend($this->member, 'remove')->assertSessionHas('success');
        $this->assertSame(0, DB::table('friendships')->count());

        // A member who does not want requests gets none, and a group without the permission has no friends at all.
        app(Privacy::class)->save($other, ['requests' => 0, 'profile' => 'everyone']);
        $this->actingAs($this->member)->get(route('members.show', $other->refresh()))->assertOk()->assertDontSee('Arkadaş ekle');
        $friend($other)->assertForbidden();

        app(Privacy::class)->save($other, ['requests' => 1, 'profile' => 'everyone']);
        $registered = UserGroup::system('registered');
        $registered->update(['permissions' => ['friends.use' => 'never'] + $registered->permissions]);
        app(PermissionResolver::class)->flush();
        $friend($other->refresh())->assertForbidden();
        $this->assertSame(0, DB::table('friendships')->count());
    }

    public function test_a_member_decides_who_sees_the_profile(): void
    {
        $owner = User::factory()->create(['username' => 'gizli']);
        $stranger = User::factory()->create();
        $moderator = User::factory()->inGroup('moderator')->create();
        app(Posting::class)->createThread($this->forum, $owner, 'Gizli üyenin konusu', 'Metin');

        // The choice is offered in the settings; the ones kept for other groups are not.
        $this->actingAs($owner)->get(route('account.edit'))->assertOk()
            ->assertSee('Profilimi kimler görebilir')->assertSee('Arkadaşlık isteklerini kabul et')
            ->assertDontSee('Bana kimler özel mesaj gönderebilir')->assertDontSee('Çevrimiçi olduğumu başkalarına göster');

        $this->put(route('account.privacy'), ['privacy' => ['profile' => 'friends', 'requests' => 1, 'messages' => 'nobody', 'online' => 0]])->assertSessionHas('success');
        $owner->refresh();
        $privacy = app(Privacy::class);
        $this->assertSame('friends', $privacy->choice($owner, 'profile'));
        $this->assertSame('members', $privacy->choice($owner, 'messages'), 'A choice the group may not make is not applied.');
        $this->assertTrue($privacy->choice($owner, 'online'));
        $this->post(route('logout'));

        // Guests and other members get the name and nothing else; the card says the same.
        $this->get(route('members.show', $owner))->assertForbidden()->assertSee('gizli')->assertSee('yalnızca üyenin arkadaşlarına')->assertDontSee('Gizli üyenin konusu');
        $this->actingAs($stranger)->get(route('members.show', $owner))->assertForbidden()->assertSee('Arkadaş ekle');
        $this->get(route('members.card', $owner))->assertOk()->assertSee('gizli')->assertSee('sınırlandırmış')->assertDontSee('Tepki puanı');

        // Friends, the staff and the member see it as usual.
        $privacy->request($stranger, $owner);
        $privacy->request($owner, $stranger);
        $this->get(route('members.show', $owner))->assertOk()->assertSee('Gizli üyenin konusu');
        $this->get(route('members.card', $owner))->assertOk()->assertSee('Tepki puanı')->assertSee('Mesaj gönder');
        $this->actingAs($moderator)->get(route('members.show', $owner))->assertOk();
        $this->actingAs($owner)->get(route('members.show', $owner))->assertOk();

        // "Members": guests are asked to sign in.
        $privacy->save($owner, ['profile' => 'members', 'requests' => 1]);
        $this->post(route('logout'));
        $this->get(route('members.show', $owner->refresh()))->assertForbidden()->assertSee('Bu profili görmek için giriş yapın');
        $this->actingAs($this->member)->get(route('members.show', $owner))->assertOk();

        // A group that loses the permission: the stored choice stops counting.
        $privacy->save($owner, ['profile' => 'nobody', 'requests' => 1]);
        $this->get(route('members.show', $owner->refresh()))->assertForbidden()->assertSee('profilini gizli tutuyor');
        $registered = UserGroup::system('registered');
        $registered->update(['permissions' => ['privacy.profile' => 'never'] + $registered->permissions]);
        app(PermissionResolver::class)->flush();
        $this->get(route('members.show', $owner))->assertOk();
    }

    public function test_contact_and_online_choices_belong_to_groups_that_have_them(): void
    {
        app(Settings::class)->set(['posting.flood_seconds' => 0]);
        $owner = User::factory()->create(['username' => 'premiumcu']);
        $owner->forceFill(['last_seen_at' => now()])->save();
        $this->premium($owner);
        $privacy = app(Privacy::class);
        $message = fn () => $this->post(route('conversations.store'), ['recipients' => 'premiumcu', 'title' => 'Merhaba orada', 'body' => 'Bir sorum olacaktı.']);
        $wall = fn () => $this->post(route('profile-posts.store', $owner), ['body' => 'Profil mesajı']);

        $this->actingAs($owner)->get(route('account.edit'))->assertOk()
            ->assertSee('Bana kimler özel mesaj gönderebilir')->assertSee('Profilime kimler yazabilir')->assertSee('Çevrimiçi olduğumu başkalarına göster');
        $this->put(route('account.privacy'), ['privacy' => ['profile' => 'everyone', 'wall' => 'friends', 'messages' => 'friends', 'requests' => 1]])->assertSessionHas('success');
        $owner->refresh();
        $this->assertFalse($privacy->choice($owner, 'online'), 'An unticked box hides the online state.');

        // Somebody who is not a friend: no message, no profile post, no online badge.
        $this->actingAs($this->member)->get(route('members.show', $owner))->assertOk()->assertDontSee('Mesaj gönder')->assertDontSee('class="presence', false);
        $this->get(route('members.card', $owner))->assertOk()->assertDontSee('class="presence', false)->assertDontSee('Son görülme')->assertDontSee('Mesaj gönder');
        $message()->assertSessionHasErrors('recipients');
        $wall()->assertForbidden();
        $this->get('/')->assertOk();
        $this->assertFalse($privacy->showsOnline($this->member, $owner));

        // A friend may do both, and the staff still see who is online.
        $privacy->request($this->member, $owner);
        $privacy->request($owner, $this->member);
        $message()->assertSessionHasNoErrors()->assertRedirect();
        $wall()->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue($privacy->showsOnline(User::factory()->inGroup('moderator')->create(), $owner));
        $this->assertTrue($privacy->showsOnline($owner, $owner));

        // Leaving the group puts the usual rules back.
        $owner->groups()->detach();
        $owner->unsetRelation('groups');
        app(PermissionResolver::class)->flush();
        $stranger = User::factory()->create();
        $this->assertTrue($privacy->canMessage($stranger, $owner));
        $this->assertTrue($privacy->showsOnline($stranger, $owner));
    }

    public function test_signature_rules_follow_the_member_group(): void
    {
        $save = fn (?string $signature) => $this->put(route('account.profile'), ['signature' => $signature]);
        $this->actingAs($this->member)->get(route('account.edit'))->assertOk()->assertSee('En fazla 200 karakter.')->assertSee('data-max="200"', false)->assertSee('data-bb="b"', false)->assertSee('data-preview-toggle', false)->assertSee('Üye grubunuz bağlantı ekleyemez.')->assertSee('maxlength="200"', false);

        // An ordinary member: short, no links, no pictures.
        $save('Sitem: [url=https://ornek.com]ornek[/url]')->assertSessionHasErrors('signature');
        $save('Bana ulaşın: www.ornek.com')->assertSessionHasErrors('signature');
        $save('[img]https://ornek.com/a.png[/img]')->assertSessionHasErrors('signature');
        $save(str_repeat('a', 201))->assertSessionHasErrors('signature');
        $this->assertNull($this->member->refresh()->signature);
        $save('[b]Hosting[/b] danışmanı')->assertSessionHasNoErrors();
        $this->assertSame('[b]Hosting[/b] danışmanı', $this->member->refresh()->signature);

        // The signature is shown under the member's posts.
        $thread = app(Posting::class)->createThread($this->forum, $this->member, 'İmzalı konu', 'Metin');
        $this->get(route('threads.show', $thread))->assertOk()->assertSee('post-sig', false);

        // A group with the extras: links, pictures and the longer limit.
        $this->premium($this->member);
        $this->get(route('account.edit'))->assertOk()->assertSee('En fazla 500 karakter.')->assertSee('Bağlantı eklenebilir.');
        $save('Sitem: [url=https://ornek.com]ornek[/url] [img]https://ornek.com/a.png[/img] '.str_repeat('a', 300))->assertSessionHasNoErrors();
        $this->assertStringContainsString('https://ornek.com', $this->member->refresh()->signature_html);
        $save(str_repeat('a', 501))->assertSessionHasErrors('signature');

        // The administrator changes the limits.
        app(Settings::class)->set(['signature.long_length' => 40]);
        $save(str_repeat('a', 41))->assertSessionHasErrors('signature');

        // A group without signatures: nothing is shown, the field is gone, what was stored stays untouched.
        $registered = UserGroup::system('registered');
        $registered->update(['permissions' => ['signature.use' => 'never'] + $registered->permissions]);
        app(PermissionResolver::class)->flush();
        $before = $this->member->refresh()->signature;
        $this->get(route('account.edit'))->assertOk()->assertSee('Üye grubunuz imza kullanamıyor.')->assertDontSee('name="signature"', false);
        $save('Yeni imza')->assertSessionHasNoErrors();
        $this->assertSame($before, $this->member->refresh()->signature);
        $this->get(route('threads.show', $thread))->assertOk()->assertDontSee('post-sig', false);
    }

    public function test_the_side_panel_and_the_member_card(): void
    {
        $this->member->forceFill(['last_seen_at' => now()])->save();

        // Icons next to the figures and the shortcuts of the side panel.
        $home = $this->actingAs($this->member)->get('/')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<span class="stat-label"><svg/', $home);
        $this->assertMatchesRegularExpression('/class="side-links">\s*<a [^>]+><svg/', $home);

        // The card: a fragment, not a page.
        $card = $this->get(route('members.card', $this->member))->assertOk()->assertSee('hostingci')->assertSee('presence on', false)->assertSee('Tepki puanı')->getContent();
        $this->assertStringNotContainsString('<html', $card);
        $this->assertStringNotContainsString('Mesaj gönder', $card, 'Nobody writes to themselves.');
        $this->post(route('logout'));
        $this->get(route('members.card', $this->member))->assertOk()->assertSee('Profil')->assertDontSee('Mesaj gönder');
    }

    public function test_alerts_and_conversations_open_as_pop_overs_in_the_header(): void
    {
        app(Settings::class)->set(['posting.flood_seconds' => 0]);
        $other = User::factory()->create(['username' => 'seocu']);

        // Guests have neither the buttons nor the lists.
        $this->get('/')->assertOk()->assertDontSee('data-pop=', false);
        $this->get(route('notifications.panel'))->assertRedirect(route('login'));
        $this->get(route('conversations.panel'))->assertRedirect(route('login'));

        // Members get both pop-overs; the links to the full pages stay.
        $this->actingAs($this->member)->get('/')->assertOk()
            ->assertSee('data-pop="/alerts/panel"', false)->assertSee('data-pop="/conversations/panel"', false)
            ->assertSee('data-pop-read="/alerts/read"', false)->assertSee('Tüm bildirimleri göster')->assertSee('Tüm konuşmaları göster')
            ->assertSee(route('notifications.index'), false)->assertSee(route('conversations.index'), false);

        // Nothing yet.
        $this->get(route('notifications.panel'))->assertOk()->assertSee('Bildiriminiz yok');
        $this->get(route('conversations.panel'))->assertOk()->assertSee('Henüz bir konuşmanız yok');

        // Somebody follows the member and writes to them.
        $this->actingAs($other)->post(route('members.follow', $this->member));
        $this->post(route('conversations.store'), ['recipients' => 'hostingci', 'title' => 'Hosting teklifi', 'body' => '[b]Merhaba[/b], sunucu fiyatlarınızı öğrenebilir miyim?'])->assertSessionHasNoErrors();

        // The alert list: a fragment with the newest alerts, unread ones marked.
        $alerts = $this->actingAs($this->member)->get(route('notifications.panel'))->assertOk()
            ->assertSee('seocu')->assertSee('class="pop-item unread"', false)->assertSee('/alerts/', false)->getContent();
        $this->assertStringNotContainsString('<html', $alerts);
        $this->assertSame(2, substr_count($alerts, 'pop-item unread'), 'The follow and the message.');

        // The conversation list: title, who it is with and a plain line of the last message.
        $list = $this->get(route('conversations.panel'))->assertOk()
            ->assertSee('Hosting teklifi')->assertSee('seocu')->assertSee('sunucu fiyatlarınızı öğrenebilir miyim?')->assertDontSee('[b]')->assertSee('class="pop-item unread"', false)->getContent();
        $this->assertStringNotContainsString('<html', $list);

        // The other side sees their own message as theirs, and it is not new to them.
        $this->actingAs($other)->get(route('conversations.panel'))->assertOk()->assertSee('Siz:')->assertDontSee('pop-item unread', false);

        // "Mark all as read" from the pop-over answers without a page.
        $this->actingAs($this->member)->postJson(route('notifications.read-all'))->assertOk()->assertExactJson(['ok' => true]);
        $this->get(route('notifications.panel'))->assertOk()->assertDontSee('pop-item unread', false)->assertSee('seocu');
        $this->assertSame(0, $this->member->unreadNotifications()->count());

        // The list is short however many alerts there are.
        foreach (range(1, 12) as $i) {
            $this->member->notify(new ForumAlert('follow', $other, route('members.show', $other)));
        }
        $this->assertSame(8, substr_count($this->get(route('notifications.panel'))->getContent(), 'class="pop-item'));
    }

    public function test_the_inbox_quota_depends_on_the_user_group(): void
    {
        app(Settings::class)->set(['posting.flood_seconds' => 0]);
        $inbox = app(Inbox::class);
        $other = User::factory()->create(['username' => 'seocu']);
        $third = User::factory()->create(['username' => 'tasarimci']);
        $moderator = User::factory()->inGroup('moderator')->create(['username' => 'modcu']);
        $admin = User::factory()->inGroup('admin')->create();
        $start = fn (string $to, string $title = 'Bir konu hakkında') => $this->post(route('conversations.store'), ['recipients' => $to, 'title' => $title, 'body' => 'Merhaba, müsait misiniz?']);

        // Out of the box: members 100, moderators 500, administrators without a limit.
        $this->assertSame([100, 500, 0], [$inbox->quota($this->member), $inbox->quota($moderator), $inbox->quota($admin)]);
        $this->actingAs($this->member)->get(route('conversations.index'))->assertOk()->assertSee('Gelen kutusu')->assertSee('0 / 100');

        // The administrator lowers the general number; two conversations fill the member's inbox.
        app(Settings::class)->set(['conversations.quota' => 2]);
        $start('seocu', 'Birinci konuşma')->assertSessionHasNoErrors();
        $this->get(route('conversations.index'))->assertOk()->assertSee('1 / 2')->assertDontSee('Gelen kutunuz dolu');
        $start('tasarimci', 'İkinci konuşma')->assertSessionHasNoErrors();
        $this->assertTrue($inbox->isFull($this->member));
        $this->get(route('conversations.index'))->assertOk()->assertSee('2 / 2')->assertSee('Gelen kutunuz dolu');
        $this->get(route('conversations.panel'))->assertOk()->assertSee('pop-quota full', false);
        $this->get(route('conversations.create'))->assertOk()->assertSee('Gelen kutunuz dolu');

        // A full inbox starts nothing new, but conversations that exist go on.
        $start('seocu', 'Üçüncü konuşma')->assertSessionHasErrors('recipients');
        $first = Conversation::where('title', 'Birinci konuşma')->firstOrFail();
        $this->post(route('conversations.reply', $first), ['body' => 'Bir şey daha soracaktım.'])->assertSessionHasNoErrors()->assertRedirect();

        // Nobody can start one with them either; the member is told once, not for every attempt.
        $this->actingAs($third);
        $start('hostingci', 'Size ulaşamıyorum')->assertSessionHasErrors('recipients');
        $start('hostingci', 'Bir daha deniyorum')->assertSessionHasErrors('recipients');
        $full = $this->member->notifications()->get()->filter(fn ($alert) => $alert->data['kind'] === 'inbox_full');
        $this->assertCount(1, $full);
        $this->assertStringContainsString('Gelen kutunuz dolu', AlertText::text($full->first()->data));
        $this->assertSame(2, $inbox->used($this->member));

        // Messages from the staff always arrive.
        $this->actingAs($moderator);
        $start('hostingci', 'Moderasyon bilgilendirmesi')->assertSessionHasNoErrors();
        $this->assertSame(3, $inbox->used($this->member));

        // Leaving conversations makes room again.
        $this->actingAs($this->member)->delete(route('conversations.leave', $first))->assertRedirect();
        $this->delete(route('conversations.leave', Conversation::where('title', 'İkinci konuşma')->firstOrFail()))->assertRedirect();
        $this->assertFalse($inbox->isFull($this->member));
        $start('seocu', 'Yer açıldı')->assertSessionHasNoErrors();

        // A user group with its own number: the most generous group counts, 0 lifts the limit.
        $this->assertTrue($inbox->isFull($this->member));
        $premium = UserGroup::create(['name' => 'Premium', 'priority' => 20, 'permissions' => [], 'conversation_quota' => 5]);
        $this->member->groups()->attach($premium->id);
        $this->member->unsetRelation('groups');
        app(PermissionResolver::class)->flush();
        $this->assertSame(5, $inbox->quota($this->member));
        $this->get(route('conversations.index'))->assertOk()->assertSee('2 / 5');
        $start('tasarimci', 'Premium ile devam')->assertSessionHasNoErrors();

        // The administrator edits the number on the group's page.
        $this->actingAs($this->member)->put(route('admin.groups.update', $premium), ['name' => 'Premium', 'priority' => 20, 'conversation_quota' => 50])->assertForbidden();
        $this->actingAs($admin)->get(route('admin.groups.edit', $premium))->assertOk()->assertSee('Gelen kutusu kotası')->assertSee('Genel ayar: 2');
        $this->put(route('admin.groups.update', $premium), ['name' => 'Premium', 'priority' => 20, 'conversation_quota' => 0])->assertSessionHasNoErrors();
        $this->assertSame(0, $inbox->quota($this->member->refresh()));
        $this->assertNull($inbox->percent($this->member));
        $this->actingAs($this->member)->get(route('conversations.index'))->assertOk()->assertDontSee('class="quota-bar"', false);

        // Empty means "as in the settings".
        $this->actingAs($admin)->put(route('admin.groups.update', $premium), ['name' => 'Premium', 'priority' => 20, 'conversation_quota' => ''])->assertSessionHasNoErrors();
        $this->assertNull($premium->refresh()->conversation_quota);
        $this->assertSame(2, $inbox->quota($this->member->refresh()));
    }

    public function test_online_ends_with_signing_out_and_names_the_device(): void
    {
        $phone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
        $desktop = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
        $this->assertSame(['mobile', 'desktop', 'tablet', 'tablet', 'mobile', 'desktop'], array_map([User::class, 'deviceFrom'], [
            $phone, $desktop, 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X)', 'Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 Chrome/130.0 Safari/537.36',
            'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36', null,
        ]));

        // Never seen: not online.
        $this->get(route('members.show', $this->member))->assertOk()->assertDontSee('presence on', false)->assertSee('presence off', false);

        // Browsing on a phone: online, with the phone named — on the profile, the card, the member list and the home page.
        $this->actingAs($this->member)->withHeader('User-Agent', $phone)->get('/')->assertOk();
        $this->assertSame('mobile', $this->member->refresh()->last_device);
        $this->assertTrue($this->member->isOnline());
        $this->get(route('members.show', $this->member))->assertOk()->assertSee('presence on', false)->assertSee('Mobil');
        $this->get(route('members.card', $this->member))->assertOk()->assertSee('presence on', false)->assertSee('Mobil');
        $this->get(route('members.index'))->assertOk()->assertSee('online-mark', false);
        $this->get('/')->assertOk()->assertSee('online-who', false);

        // The next visit comes from a computer: the device changes at once, without waiting a minute.
        $this->withHeader('User-Agent', $desktop)->get('/')->assertOk();
        $this->assertSame('desktop', $this->member->refresh()->last_device);
        $this->get(route('members.show', $this->member))->assertOk()->assertSee('Bilgisayar')->assertDontSee('Mobil');

        // Signing out: offline straight away for everybody, although the last visit was seconds ago.
        $this->post(route('logout'))->assertRedirect();
        $this->assertFalse($this->member->refresh()->isOnline());
        $this->assertNotNull($this->member->signed_out_at);
        $this->assertSame(0, User::online()->count());
        $this->get(route('members.show', $this->member))->assertOk()->assertDontSee('presence on', false)->assertSee('presence off', false);
        $this->get(route('members.card', $this->member))->assertOk()->assertDontSee('presence on', false);
        $this->get('/')->assertOk()->assertDontSee('online-who', false)->assertSee('Şu anda çevrimiçi kimse yok');

        // Back again: online from the first request on.
        $this->actingAs($this->member)->withHeader('User-Agent', $phone)->get('/')->assertOk();
        $this->assertTrue($this->member->refresh()->isOnline());
        $this->assertNull($this->member->signed_out_at);
        $this->assertSame(1, User::online()->count());

        // Closing the browser without signing out: gone after a few minutes.
        $this->travel(6)->minutes();
        $this->assertFalse($this->member->refresh()->isOnline());
        $this->travelBack();

        // A member who hides the online state shows neither the badge nor the device.
        $registered = UserGroup::system('registered');
        $registered->update(['permissions' => ['privacy.online' => 'allow'] + $registered->permissions]);
        app(PermissionResolver::class)->flush();
        app(Privacy::class)->save($this->member, ['profile' => 'everyone', 'requests' => 1]);
        $this->post(route('logout'));
        $other = User::factory()->create();
        $this->actingAs($this->member)->withHeader('User-Agent', $phone)->get('/')->assertOk();
        $this->actingAs($other)->get(route('members.show', $this->member))->assertOk()->assertDontSee('class="presence', false);
        $this->assertSame(1, substr_count($this->get(route('members.index'))->getContent(), 'class="online-mark"'), 'Only the viewer, who does not hide it.');
    }

    public function test_the_footer_shows_the_board_in_numbers_and_its_busiest_forums(): void
    {
        app(Posting::class)->createThread($this->forum, $this->member, 'Footer için konu', 'Metin');
        $private = Node::create(['type' => 'forum', 'title' => 'Ekip Odası', 'parent_id' => $this->forum->parent_id, 'post_count' => 50]);
        foreach (['guest', 'registered'] as $key) {
            NodePermission::create(['node_id' => $private->id, 'user_group_id' => UserGroup::system($key)->id, 'permissions' => ['node.view' => 'never']]);
        }
        app(NodeTree::class)->flush();

        // Asked for twice: the second page is built from the cache, which gives plain values back only.
        foreach ([1, 2] as $visit) {
            $this->get('/')->assertOk()
                ->assertSee('footer-stats', false)->assertSee('Şu an çevrimiçi')->assertSee('En yeni üye')->assertSee(route('members.show', $this->member), false)
                ->assertSee('En hareketli forumlar')->assertSee('Genel Sohbet')->assertSee('RSS')
                ->assertSee('footer-join', false)->assertSee('data-to-top', false)->assertSee('Community platform by')
                ->assertDontSee('Ekip Odası');
        }
        foreach (['forum.footer.members', 'forum.footer.online', 'forum.footer.newest_member'] as $key) {
            $this->assertFalse(is_object(Cache::get($key)), "$key must not hold an object");
        }
        $this->assertSame(['id' => $this->member->id, 'username' => 'hostingci'], Cache::get('forum.footer.newest_member'));

        // Members get their own shortcut instead of the invitation.
        $this->actingAs($this->member)->get(route('members.index'))->assertOk()->assertSee('footer-stats', false)->assertDontSee('footer-join', false);
    }

    public function test_every_page_has_one_search_engine_friendly_address(): void
    {
        $thread = app(Posting::class)->createThread($this->forum, $this->member, 'Şifremi Unuttum, Ne Yapmalıyım? (2026)', 'Metin');
        $address = route('threads.show', $thread);

        // Lower case, Turkish letters written plainly, hyphens, the number at the end.
        $this->assertSame(url('/threads/sifremi-unuttum-ne-yapmaliyim-2026-'.$thread->id), $address);
        $this->assertSame(url('/members/hostingci-'.$this->member->id), route('members.show', $this->member));
        $this->get($address)->assertOk()->assertSee('<link rel="canonical" href="'.$address.'">', false);

        // Any other spelling moves permanently to it: the old dotted form, the bare number, a wrong or outdated title.
        foreach (['sifremi-unuttum-ne-yapmaliyim-2026.'.$thread->id, (string) $thread->id, 'eski-baslik-'.$thread->id, 'SIFREMI-'.$thread->id] as $other) {
            $this->get('/threads/'.$other)->assertStatus(301)->assertRedirect($address);
        }
        $this->get('/threads/eski-baslik.'.$thread->id.'?page=2')->assertStatus(301)->assertRedirect($address.'?page=2');
        $this->get('/members/hostingci.'.$this->member->id)->assertStatus(301)->assertRedirect(route('members.show', $this->member));
        $this->get('/members/'.$this->member->id.'/feedback')->assertStatus(301);

        // After a rename the old address follows the thread.
        $thread->update(['title' => 'Yeni Başlık']);
        $this->get($address)->assertStatus(301)->assertRedirect(url('/threads/yeni-baslik-'.$thread->id));

        // Nothing is guessed: no number, no page.
        $this->get('/threads/sifremi-unuttum')->assertNotFound();
        $this->get('/threads/yok-999999')->assertNotFound();

        // Forms and scripts are answered where they are; only pages move.
        $this->getJson('/members/'.$this->member->id.'/card')->assertOk();
        $this->actingAs($this->member)->post('/members/'.User::factory()->create()->id.'/follow')->assertRedirect();

        // Pages without an address of their own name the one they were asked for, without stray parameters.
        $this->get('/members?sort=posts&page=2')->assertOk()->assertSee('<link rel="canonical" href="'.url('/members').'?page=2">', false);
        $this->get(route('nodes.show', $this->forum))->assertOk()->assertSee('<link rel="canonical" href="'.route('nodes.show', $this->forum).'">', false);

        // Guests are linked to threads directly.
        $this->post(route('logout'));
        $this->get('/')->assertOk()->assertDontSee('/unread"', false);
    }

    public function test_the_administrator_uploads_own_icons_for_categories_and_forums(): void
    {
        Storage::fake('public');
        $admin = User::factory()->inGroup('admin')->create();
        $category = Node::findOrFail($this->forum->parent_id);
        $fields = fn (Node $node) => ['title' => $node->title, 'type' => $node->type, 'parent_id' => $node->parent_id, 'description' => $node->description, 'is_active' => 1];
        $save = fn (Node $node, array $extra) => $this->put(route('admin.nodes.update', $node), $fields($node) + $extra);

        // Standard icons to begin with; only administrators change them.
        $this->get('/')->assertOk()->assertDontSee('has-image', false);
        $this->actingAs($this->member);
        $save($this->forum, ['icon' => UploadedFile::fake()->image('ikon.png', 96, 96)])->assertForbidden();

        $this->actingAs($admin)->get(route('admin.nodes.edit', $this->forum))->assertOk()->assertSee('Özel ikon')->assertSee('enctype="multipart/form-data"', false);

        // Not a picture, a vector file or a huge one: refused.
        $save($this->forum, ['icon' => UploadedFile::fake()->create('ikon.php', 5, 'application/x-php')])->assertSessionHasErrors('icon');
        $save($this->forum, ['icon' => UploadedFile::fake()->create('ikon.svg', 5, 'image/svg+xml')])->assertSessionHasErrors('icon');
        $save($this->forum, ['icon' => UploadedFile::fake()->image('dev.png', 3000, 3000)])->assertSessionHasErrors('icon');
        $this->assertNull($this->forum->refresh()->icon_path);

        // A forum and its category each get their picture.
        $save($this->forum, ['icon' => UploadedFile::fake()->image('ikon.png', 96, 96)])->assertSessionHasNoErrors();
        $save($category, ['icon' => UploadedFile::fake()->image('kategori.webp', 64, 64)])->assertSessionHasNoErrors();
        $forumIcon = $this->forum->refresh()->icon_path;
        $categoryIcon = $category->refresh()->icon_path;
        Storage::disk('public')->assertExists($forumIcon);
        $this->post(route('logout'));
        $home = $this->get('/')->assertOk()->assertSee('node-icon has-image', false)->assertSee('cat-mark has-image', false)->assertSee(basename($forumIcon))->assertSee(basename($categoryIcon))->getContent();
        $this->assertSame(1, substr_count($home, 'node-icon has-image'), 'The other forums keep the standard icon.');

        // Saving other fields keeps the icon; a new upload replaces the file; ticking the box goes back to the standard icon.
        $this->actingAs($admin);
        $save($this->forum, ['description' => 'Yeni açıklama'])->assertSessionHasNoErrors();
        $this->assertSame($forumIcon, $this->forum->refresh()->icon_path);
        $save($this->forum, ['icon' => UploadedFile::fake()->image('yeni.jpg', 128, 128)])->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($forumIcon);
        $second = $this->forum->refresh()->icon_path;
        Storage::disk('public')->assertExists($second);
        $this->get(route('admin.nodes.edit', $this->forum))->assertOk()->assertSee('name="remove_icon"', false);
        $save($this->forum, ['remove_icon' => 1])->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($second);
        $this->assertNull($this->forum->refresh()->icon_path);

        // A new node can be created with its icon, and deleting the node deletes the file.
        $this->post(route('admin.nodes.store'), ['title' => 'İkonlu Forum', 'type' => 'forum', 'parent_id' => $category->id, 'is_active' => 1, 'icon' => UploadedFile::fake()->image('a.gif', 48, 48)])->assertSessionHasNoErrors();
        $new = Node::where('title', 'İkonlu Forum')->firstOrFail();
        Storage::disk('public')->assertExists($new->icon_path);
        $this->delete(route('admin.nodes.destroy', $new))->assertRedirect();
        Storage::disk('public')->assertMissing($new->icon_path);
    }

    public function test_replies_are_posted_and_arrive_without_reloading_the_page(): void
    {
        app(Settings::class)->set(['posting.flood_seconds' => 0, 'board.posts_per_page' => 5]);
        $other = User::factory()->create(['username' => 'seocu']);
        $thread = app(Posting::class)->createThread($this->forum, $this->member, 'Canlı konu', 'İlk mesaj');
        $first = $thread->first_post_id;

        // The page carries what the script needs: a box for the posts, the address to ask for news, the live form.
        $this->actingAs($this->member)->get(route('threads.show', $thread))->assertOk()
            ->assertSee('data-thread-posts', false)->assertSee('data-post-list', false)->assertSee('data-new-posts', false)
            ->assertSee('data-since="'.url('/threads/'.$thread->getRouteKey().'/since').'"', false)->assertSee('data-live-reply', false)
            ->assertSee('data-mentions="/members/suggest"', false);

        // A reply sent by script is answered with where it is; a normal form post still redirects.
        $json = $this->postJson(route('posts.store', $thread), ['body' => 'Betikle gönderilen cevap'])->assertOk()->json();
        $this->assertTrue($json['visible']);
        $this->assertSame(route('threads.show', $thread), $json['url']);
        $this->get($json['url'])->assertOk()->assertSee('id="post-'.$json['id'].'"', false);
        $this->post(route('posts.store', $thread), ['body' => 'Formla gönderilen cevap'])->assertRedirect();
        $this->postJson(route('posts.store', $thread), ['body' => ''])->assertStatus(422)->assertJsonValidationErrors('body');

        // News for a reader: other people's posts after the one they have, not their own.
        $since = fn (int $after) => $this->getJson(route('threads.since', [$thread, $after]))->assertOk()->json();
        $this->assertSame(['count' => 0, 'url' => null], $since($json['id'] + 1));
        $this->actingAs($other)->postJson(route('posts.store', $thread), ['body' => 'Başkasının cevabı'])->assertOk();
        $this->assertSame(0, $since($first)['count'] - 2, 'The other member sees the two replies of the thread owner.');
        $news = $this->actingAs($this->member)->getJson(route('threads.since', [$thread, $json['id'] + 1]))->json();
        $this->assertSame(1, $news['count']);
        $this->assertSame(route('threads.show', $thread), $news['url']);

        // When the thread grows onto another page the answer points there.
        foreach (range(1, 3) as $i) {
            $this->actingAs($other)->postJson(route('posts.store', $thread), ['body' => 'Sayfayı dolduran cevap '.$i])->assertOk();
        }
        $last = $this->actingAs($this->member)->postJson(route('posts.store', $thread), ['body' => 'İkinci sayfanın cevabı'])->json();
        $this->assertSame(route('threads.show', ['thread' => $thread, 'page' => 2]), $last['url']);
        // Only the last page asks for news.
        $this->get(route('threads.show', $thread))->assertOk()->assertDontSee('data-since=', false);
        $this->get($last['url'])->assertOk()->assertSee('data-since=', false);

        // Hidden forums stay hidden, guests can read along in public ones.
        $this->post(route('logout'));
        $this->getJson(route('threads.since', [$thread, $first]))->assertOk()->assertJsonPath('count', 7);
        NodePermission::create(['node_id' => $this->forum->id, 'user_group_id' => UserGroup::system('guest')->id, 'permissions' => ['node.view' => 'never']]);
        app(PermissionResolver::class)->flush();
        app(NodeTree::class)->flush();
        $this->assertContains($this->getJson(route('threads.since', [$thread, $first]))->status(), [401, 403, 404]);
    }

    public function test_the_editor_suggests_members_and_keeps_saved_replies(): void
    {
        User::factory()->create(['username' => 'hostmaster']);
        User::factory()->create(['username' => 'hosting_uzmani', 'is_banned' => true]);
        User::factory()->create(['username' => 'seocu']);

        // "@ho…": members whose name starts with it; one letter is too little, guests are not answered, wildcards are plain text.
        $this->getJson(route('members.suggest', ['q' => 'ho']))->assertUnauthorized();
        $names = fn (string $q) => array_column($this->getJson(route('members.suggest', ['q' => $q]))->assertOk()->json('members'), 'name');
        $this->actingAs($this->member);
        $this->assertEqualsCanonicalizing(['hostingci', 'hostmaster'], $names('ho'));
        $this->assertSame([], $names('h'));
        $this->assertSame([], $names('%%'));
        $this->assertSame(['seocu'], $names('seo'));

        // Saved replies: the button is in the editor, the list is the member's own.
        $this->get(route('threads.create', $this->forum))->assertOk()->assertSee('data-saved-toggle', false)->assertSee('data-url="/saved-replies"', false);
        $this->get(route('replies.index'))->assertOk()->assertSee('Hazır cevaplar')->assertSee('Hazır cevap ekle');
        $this->post(route('replies.store'), ['title' => 'Satış koşulları', 'body' => "Ödeme [b]peşin[/b] alınır.\nTeslimat aynı gün."])->assertSessionHas('success');
        $this->post(route('replies.store'), ['title' => '', 'body' => 'x'])->assertSessionHasErrors(['title', 'body']);
        $list = $this->getJson(route('replies.index'))->assertOk()->json('replies');
        $this->assertSame(['Satış koşulları'], array_column($list, 'title'));
        $this->assertStringContainsString('[b]peşin[/b]', $list[0]['body']);

        $this->put(route('replies.update', $list[0]['id']), ['title' => 'Koşullar', 'body' => 'Yeni metin'])->assertSessionHas('success');
        $this->get(route('replies.index'))->assertOk()->assertSee('Koşullar')->assertSee('Yeni metin');

        // Nobody else reads, changes or deletes them.
        $other = User::factory()->create();
        $this->actingAs($other);
        $this->assertSame([], $this->getJson(route('replies.index'))->json('replies'));
        $this->put(route('replies.update', $list[0]['id']), ['title' => 'Benim', 'body' => 'Değişti'])->assertNotFound();
        $this->delete(route('replies.destroy', $list[0]['id']))->assertNotFound();

        // The administrator sets how many a member can keep, and which groups have them at all.
        app(Settings::class)->set(['replies.max' => 1]);
        $this->actingAs($this->member)->post(route('replies.store'), ['title' => 'İkinci', 'body' => 'Sığmaz'])->assertSessionHasErrors('title');
        $this->delete(route('replies.destroy', $list[0]['id']))->assertSessionHas('success');
        $this->assertSame(0, DB::table('saved_replies')->count());

        $registered = UserGroup::system('registered');
        $registered->update(['permissions' => ['replies.saved' => 'never'] + $registered->permissions]);
        app(PermissionResolver::class)->flush();
        $this->get(route('replies.index'))->assertForbidden();
        $this->get(route('threads.create', $this->forum))->assertOk()->assertDontSee('data-saved-toggle', false);
    }

    public function test_members_find_their_own_content_and_filter_a_forum(): void
    {
        app(Settings::class)->set(['posting.flood_seconds' => 0]);
        $other = User::factory()->create(['username' => 'seocu']);
        $posting = app(Posting::class);
        $mine = $posting->createThread($this->forum, $this->member, 'Benim açtığım konu', 'İlk mesajım');
        $theirs = $posting->createThread($this->forum, $other, 'Başkasının konusu', 'Onun mesajı');
        $quiet = $posting->createThread($this->forum, $other, 'Cevapsız kalan konu', 'Kimse yazmadı');
        $posting->reply($theirs, $this->member, 'Katıldığım cevap', '127.0.0.1');
        $theirs->refresh()->update(['view_count' => 500]);

        // "Mine" is for members.
        $this->get(route('my'))->assertRedirect(route('login'));
        $this->actingAs($this->member);
        $this->get(route('my', ['tab' => 'yok']))->assertNotFound();

        $this->get(route('my', ['tab' => 'threads']))->assertOk()->assertSee('Konularım')->assertSee('Benim açtığım konu')->assertDontSee('Başkasının konusu');
        $this->get(route('my', ['tab' => 'joined']))->assertOk()->assertSee('Başkasının konusu')->assertDontSee('Benim açtığım konu')->assertDontSee('Cevapsız kalan konu');
        $this->get(route('my', ['tab' => 'posts']))->assertOk()->assertSee('Katıldığım cevap')->assertSee('İlk mesajım')->assertDontSee('Onun mesajı');

        // Unread: what was active and not opened; opening a thread takes it off the list, so does "mark read".
        $this->get(route('my'))->assertOk()->assertSee('Okunmamışlar')->assertSee('Cevapsız kalan konu');
        $this->get(route('threads.show', $quiet))->assertOk();
        $this->get(route('my'))->assertOk()->assertDontSee('Cevapsız kalan konu');
        $this->travel(2)->seconds();
        $this->post(route('forum.mark-read'));
        $this->get(route('my'))->assertOk()->assertSee('Her şeyi okudunuz');
        $this->travelBack();

        // A hidden forum's threads are in none of the lists.
        $private = Node::create(['type' => 'forum', 'title' => 'Ekip Odası', 'parent_id' => $this->forum->parent_id]);
        $secret = $posting->createThread($private, $this->member, 'Gizli forumdaki konum', 'Metin');
        NodePermission::create(['node_id' => $private->id, 'user_group_id' => UserGroup::system('registered')->id, 'permissions' => ['node.view' => 'never']]);
        app(PermissionResolver::class)->flush();
        app(NodeTree::class)->flush();
        $this->get(route('my', ['tab' => 'threads']))->assertOk()->assertDontSee('Gizli forumdaki konum');
        $this->get(route('my', ['tab' => 'posts']))->assertOk()->assertDontSee('Gizli forumdaki konum');

        // ---- Inside a forum: filters and sorting -------------------------------------------------------
        $forum = route('nodes.show', $this->forum);
        $titles = fn (string $query) => collect(['Benim açtığım konu', 'Başkasının konusu', 'Cevapsız kalan konu'])
            ->filter(fn ($title) => str_contains($html = $this->get($forum.$query)->assertOk()->getContent(), '>'.$title.'<') || str_contains($html, $title))->values()->all();

        $this->get($forum)->assertOk()->assertSee('data-auto-submit', false)->assertSee('Cevapsız')->assertSee('En çok görüntülenen')->assertDontSee('filter-clear', false);
        $this->assertSame(['Benim açtığım konu', 'Cevapsız kalan konu'], $titles('?show=unanswered'));
        $this->assertSame(['Benim açtığım konu'], $titles('?show=mine'));
        $this->get($forum.'?show=unanswered')->assertSee('filter-clear', false);

        // Most views first; the choice travels with the page links.
        $page = $this->get($forum.'?sort=views')->getContent();
        $this->assertTrue(strpos($page, 'Başkasının konusu') < strpos($page, 'Benim açtığım konu'));
        $page = $this->get($forum.'?sort=new')->getContent();
        $this->assertTrue(strpos($page, 'Cevapsız kalan konu') < strpos($page, 'Benim açtığım konu'));

        // With a filter or another order the sticky/normal headings make no sense and are left out.
        $mine->update(['is_sticky' => true]);
        $this->get($forum)->assertOk()->assertSee('Sabit konular');
        $this->get($forum.'?sort=replies')->assertOk()->assertDontSee('Sabit konular');
        $this->get($forum.'?show=nonsense&sort=nonsense')->assertOk()->assertSee('Sabit konular');

        // Guests have no "my threads"; question forums get solved / not solved.
        $this->get($forum)->assertDontSee('Henüz çözülmemiş');
        $this->forum->update(['allow_solutions' => true]);
        app(NodeTree::class)->flush();
        $this->get($forum)->assertSee('Henüz çözülmemiş');
        $this->post(route('logout'));
        $this->get($forum.'?show=mine')->assertOk()->assertSee('Başkasının konusu')->assertDontSee('value="mine"', false);

        // Every page: forum jump without hidden forums, scroll buttons, the phone bar.
        $this->get('/')->assertOk()->assertSee('data-jump', false)->assertSee('Foruma git')->assertSee('data-scroll-buttons', false)
            ->assertSee('class="mobile-nav"', false);
        $this->actingAs($this->member)->get('/')->assertOk()->assertSee(route('my'), false)->assertSee('İçeriklerim')->assertDontSee('Ekip Odası');
    }

    public function test_members_choose_which_alerts_they_get_and_how(): void
    {
        Mail::fake();
        app(Settings::class)->set(['posting.flood_seconds' => 0]);
        config(['mail.default' => 'smtp']);
        $prefs = app(AlertPrefs::class);
        $other = User::factory()->create(['username' => 'seocu']);
        $this->member->forceFill(['email_verified_at' => now(), 'email_on_reply' => true, 'email_on_message' => false])->save();
        $mailsTo = fn (User $who) => Mail::queued(AlertMail::class, fn ($mail) => $mail->hasTo($who->email))->count();

        // Before any choice: everything on the site, e-mail as the two older switches say.
        $start = $prefs->all($this->member);
        $this->assertSame([true, true, true], array_values($start['replies']));
        $this->assertSame([true, false, true], array_values($start['mentions']));
        $this->assertSame([true, false, true], array_values($start['social']));

        $this->actingAs($this->member)->get(route('account.edit'))->assertOk()->assertSee('Bildirim tercihleri')->assertSee('name="alerts[social][site]"', false)->assertSee('Biri benden bahsettiğinde');

        // No alerts about followers at all; mentions also by e-mail; replies no longer by e-mail.
        $this->put(route('account.alerts'), ['alerts' => [
            'replies' => ['site' => 1, 'push' => 1],
            'mentions' => ['site' => 1, 'mail' => 1],
            'conversations' => ['site' => 1],
            'reactions' => ['site' => 1],
            'account' => ['mail' => 1],
        ]])->assertSessionHas('success');
        $saved = $prefs->all($this->member->refresh());
        $this->assertFalse($saved['social']['site']);
        $this->assertFalse($saved['social']['push'], 'No browser notification without the alert.');
        $this->assertTrue($saved['account']['site'], 'Alerts about the account cannot be switched off on the site.');
        $this->assertSame([false, true], [$this->member->email_on_reply, $this->member->email_on_message]);

        // A follower: no alert, no e-mail.
        $this->actingAs($other)->post(route('members.follow', $this->member));
        $this->assertSame(0, $this->member->notifications()->count());
        $this->assertSame(0, $mailsTo($this->member));

        // A mention: alert and e-mail.
        $thread = app(Posting::class)->createThread($this->forum, $other, 'Bahsetme konusu', 'Selam @hostingci, bakar mısın?');
        $this->assertSame(['mention'], $this->member->notifications()->get()->pluck('data.kind')->all());
        $this->assertSame(1, $mailsTo($this->member));

        // Wanted by e-mail only: the e-mail goes out although nothing is shown on the site.
        $this->actingAs($this->member)->put(route('account.alerts'), ['alerts' => ['social' => ['mail' => 1], 'account' => ['site' => 1]]])->assertSessionHas('success');
        $this->member->refresh()->notifications()->delete();
        $this->actingAs($other)->post(route('profile-posts.store', $this->member), ['body' => 'Profiline bir not bıraktım.']);
        $this->assertSame(0, $this->member->notifications()->count());
        $this->assertSame(2, $mailsTo($this->member));

        // Alerts of add-ons, which belong to no group, are always shown.
        $this->member->notify(new ForumAlert('shop_delivered', null, url('/'), ['text' => 'Siparişiniz teslim edildi.']));
        $this->assertSame(1, $this->member->notifications()->count());

        // The older preferences form no longer touches the e-mail switches.
        $this->actingAs($this->member)->put(route('account.preferences'), ['theme' => 'auto', 'locale' => 'tr', 'email_digest' => 'none', 'keep_mail' => 1])->assertSessionHas('success');
        $this->assertTrue($prefs->all($this->member->refresh())['social']['mail']);
    }

    public function test_conversations_can_be_searched_handled_together_and_show_who_has_seen_them(): void
    {
        app(Settings::class)->set(['posting.flood_seconds' => 0]);
        $other = User::factory()->create(['username' => 'seocu']);
        $third = User::factory()->create(['username' => 'tasarimci']);
        $start = fn (string $to, string $title, string $body) => $this->post(route('conversations.store'), ['recipients' => $to, 'title' => $title, 'body' => $body]);

        $this->actingAs($this->member);
        $start('seocu', 'Hosting teklifi', 'Sunucu fiyatlarını konuşalım.')->assertSessionHasNoErrors();
        $start('tasarimci', 'Logo çalışması', 'Yeni logo için brif ekte.')->assertSessionHasNoErrors();
        $hosting = Conversation::where('title', 'Hosting teklifi')->firstOrFail();
        $logo = Conversation::where('title', 'Logo çalışması')->firstOrFail();

        // Search: by title, by a word in a message, by the other member's name.
        $this->get(route('conversations.index'))->assertOk()->assertSee('Konuşmalarda ara')->assertSee('Hosting teklifi')->assertSee('Logo çalışması');
        $this->get(route('conversations.index', ['q' => 'hosting']))->assertOk()->assertSee('Hosting teklifi')->assertDontSee('Logo çalışması');
        $this->get(route('conversations.index', ['q' => 'brif']))->assertOk()->assertSee('Logo çalışması')->assertDontSee('Hosting teklifi');
        $this->get(route('conversations.index', ['q' => 'tasarimci']))->assertOk()->assertSee('Logo çalışması')->assertDontSee('Hosting teklifi');
        $this->get(route('conversations.index', ['q' => 'yok-böyle-bir-şey']))->assertOk()->assertSee('Aramanıza uyan konuşma yok.');

        // "Seen" appears once the other side has opened the conversation after my last message.
        $this->get(route('conversations.show', $hosting))->assertOk()->assertDontSee('seen-line', false);
        $this->travel(5)->seconds();
        $this->actingAs($other)->get(route('conversations.show', $hosting))->assertOk()->assertDontSee('seen-line', false);
        $this->actingAs($this->member)->get(route('conversations.show', $hosting))->assertOk()->assertSee('seen-line', false)->assertSee('Görüldü: seocu');

        // Mark as unread from the conversation; the list shows it as new again.
        $unread = fn (User $who) => DB::table('conversation_participants')->where('user_id', $who->id)->whereNull('last_read_at')->count();
        $this->assertSame(0, $unread($this->member));
        $this->post(route('conversations.unread', $hosting))->assertRedirect(route('conversations.index'));
        $this->assertSame(1, $unread($this->member));
        $this->actingAs($third)->post(route('conversations.unread', $hosting))->assertNotFound();

        // Several at once: read, unread, leave — and only one's own.
        $this->actingAs($this->member);
        $this->post(route('conversations.bulk'), ['action' => 'read'])->assertSessionHasErrors('ids');
        $this->post(route('conversations.bulk'), ['action' => 'read', 'ids' => [$hosting->id, $logo->id]])->assertSessionHas('success');
        $this->assertSame(0, $unread($this->member));
        $this->post(route('conversations.bulk'), ['action' => 'unread', 'ids' => [$hosting->id, $logo->id]])->assertSessionHas('success');
        $this->assertSame(2, $unread($this->member));

        $foreign = $this->actingAs($other)->post(route('conversations.store'), ['recipients' => 'tasarimci', 'title' => 'Bana ait olmayan', 'body' => 'İkimizin arasında.']);
        $notMine = Conversation::where('title', 'Bana ait olmayan')->firstOrFail();
        $this->actingAs($this->member)->post(route('conversations.bulk'), ['action' => 'leave', 'ids' => [$hosting->id, $logo->id, $notMine->id]])->assertSessionHas('success');
        $this->assertSame(0, DB::table('conversation_participants')->where('user_id', $this->member->id)->count());
        $this->assertSame(2, DB::table('conversation_participants')->where('conversation_id', $notMine->id)->count(), 'Other people\'s conversations are not touched.');
        $this->get(route('conversations.index'))->assertOk()->assertDontSee('bulk-bar', false);
    }

    public function test_a_member_changes_the_username_and_asks_for_the_account_to_be_deleted(): void
    {
        Storage::fake('public');
        $admin = User::factory()->inGroup('admin')->create();
        $this->member->forceFill(['password' => 'gizli-parola-1', 'about' => 'Hakkımda yazı', 'location' => 'İzmir'])->save();
        $thread = app(Posting::class)->createThread($this->forum, $this->member, 'Kalacak konu', 'Bu mesaj silinmez.');

        // Changing the username is a group permission; ordinary members do not have it out of the box.
        $this->actingAs($this->member)->get(route('account.edit'))->assertOk()->assertDontSee('id="username"', false)->assertSee('Hesabımı sil');
        $this->put(route('account.rename'), ['username' => 'yeni_ad', 'current_password' => 'gizli-parola-1'])->assertForbidden();

        $registered = UserGroup::system('registered');
        $registered->update(['permissions' => ['account.rename' => 'allow'] + $registered->permissions]);
        app(PermissionResolver::class)->flush();
        $this->get(route('account.edit'))->assertOk()->assertSee('Kullanıcı adını değiştir');

        // The password is asked for, the name must be free and well formed.
        $this->put(route('account.rename'), ['username' => 'yeni_ad', 'current_password' => 'yanlış'])->assertSessionHasErrors('current_password');
        $this->put(route('account.rename'), ['username' => $admin->username, 'current_password' => 'gizli-parola-1'])->assertSessionHasErrors('username');
        $this->put(route('account.rename'), ['username' => 'boşluk var', 'current_password' => 'gizli-parola-1'])->assertSessionHasErrors('username');
        $old = route('members.show', $this->member);

        $this->put(route('account.rename'), ['username' => 'yeni_ad', 'current_password' => 'gizli-parola-1'])->assertSessionHas('success');
        $this->assertSame('yeni_ad', $this->member->refresh()->username);
        $this->assertDatabaseHas('username_changes', ['user_id' => $this->member->id, 'old_name' => 'hostingci', 'new_name' => 'yeni_ad']);
        // The old profile address moves to the new one.
        $this->get($old)->assertStatus(301)->assertRedirect(route('members.show', $this->member));

        // Not again right away; the administrator sets the waiting time.
        $this->put(route('account.rename'), ['username' => 'bir_daha', 'current_password' => 'gizli-parola-1'])->assertSessionHasErrors('username');
        $this->get(route('account.edit'))->assertOk()->assertSee('yeniden değiştirebilirsiniz');
        app(Settings::class)->set(['account.rename_days' => 0]);
        $this->put(route('account.rename'), ['username' => 'bir_daha', 'current_password' => 'gizli-parola-1'])->assertSessionHas('success');

        // ---- Asking for deletion -------------------------------------------------------------------------
        $this->post(route('account.delete'), ['current_password' => 'yanlış'])->assertSessionHasErrors('current_password');
        $this->post(route('account.delete'), ['current_password' => 'gizli-parola-1', 'reason' => 'Artık kullanmıyorum'])->assertSessionHas('success');
        $this->assertNotNull($this->member->refresh()->deletion_requested_at);
        $this->assertStringContainsString('hesabının silinmesini istiyor', AlertText::text($admin->notifications()->first()->data));
        $this->get(route('account.edit'))->assertOk()->assertSee('Vazgeçtim: hesabım kalsın');

        // The member can take it back.
        $this->delete(route('account.delete.cancel'))->assertSessionHas('success');
        $this->assertNull($this->member->refresh()->deletion_requested_at);
        $this->post(route('account.delete'), ['current_password' => 'gizli-parola-1', 'reason' => 'Artık kullanmıyorum']);

        // Only administrators decide.
        $this->get(route('admin.deletions'))->assertForbidden();
        $this->post(route('admin.deletions.decide', $this->member), ['action' => 'delete'])->assertForbidden();
        $this->actingAs($admin)->get(route('admin.deletions'))->assertOk()->assertSee('bir_daha')->assertSee('Artık kullanmıyorum');

        // "Keep" closes the request and tells the member.
        $this->post(route('admin.deletions.decide', $this->member), ['action' => 'keep'])->assertSessionHas('success');
        $this->assertNull($this->member->refresh()->deletion_requested_at);
        $this->assertFalse($this->member->isBanned());
        $this->post(route('admin.deletions.decide', $this->member), ['action' => 'delete'])->assertNotFound();

        // "Delete": nothing personal is left, the account is closed, the posts stay.
        $this->member->forceFill(['deletion_requested_at' => now()])->save();
        $email = $this->member->email;
        $this->post(route('admin.deletions.decide', $this->member), ['action' => 'delete'])->assertSessionHas('success');
        $gone = $this->member->refresh();
        $this->assertSame('silinen-uye-'.$gone->id, $gone->username);
        $this->assertNotSame($email, $gone->email);
        $this->assertNull($gone->about);
        $this->assertNull($gone->location);
        $this->assertTrue($gone->isBanned());
        $this->assertSame(0, DB::table('username_changes')->where('user_id', $gone->id)->count());
        $this->post(route('logout'));
        $this->post(route('login'), ['login' => $email, 'password' => 'gizli-parola-1'])->assertSessionHasErrors();
        $this->get(route('threads.show', $thread->refresh()))->assertOk()->assertSee('Bu mesaj silinmez.')->assertDontSee('bir_daha');

        // An administrator cannot be deleted this way.
        $this->actingAs($admin)->get(route('account.edit'))->assertOk()->assertDontSee('id="delete-account"', false);
    }

    public function test_a_new_member_is_welcomed_step_by_step(): void
    {
        $this->get(route('welcome'))->assertRedirect(route('login'));
        $this->get(route('welcome.profile'))->assertRedirect(route('login'));

        // ---- Step 1: forums, with tools to tick all of them or none ----------------------------------
        $fresh = User::factory()->create(['username' => 'yenigelen', 'email_verified_at' => null]);
        $this->actingAs($fresh)->get(route('welcome'))->assertOk()
            ->assertSee('Hoş geldiniz, yenigelen!')->assertSee('Hangi forumları takip etmek istersiniz?')->assertSee('Genel Sohbet')
            ->assertSee('wizard-steps', false)->assertSee('aria-current="step"', false)
            ->assertSee('data-check="all"', false)->assertSee('Tümünü seç')->assertSee('Tümünü kaldır')->assertSee('Seçimi ters çevir')
            ->assertSee('data-check-toggle', false)->assertSee('Kaydet ve devam et')->assertSee('Bu adımı atla')
            ->assertDontSee('Kendinizi tanıtın')->assertDontSee('Hazırsınız');

        // The home page reminds the member until the profile is complete.
        $this->get('/')->assertOk()->assertSee('profile-meter', false);

        // Choosing forums follows them and leads to the next step; a forum the member cannot see is not accepted.
        $private = Node::create(['type' => 'forum', 'title' => 'Ekip Odası', 'parent_id' => $this->forum->parent_id]);
        NodePermission::create(['node_id' => $private->id, 'user_group_id' => UserGroup::system('registered')->id, 'permissions' => ['node.view' => 'never']]);
        app(PermissionResolver::class)->flush();
        app(NodeTree::class)->flush();
        $this->get(route('welcome'))->assertOk()->assertDontSee('Ekip Odası');
        $this->post(route('welcome.save'), ['forums' => [$this->forum->id, $private->id, 999999]])->assertRedirect(route('welcome.profile'));
        $this->assertSame([$this->forum->id], DB::table('node_watches')->where('user_id', $fresh->id)->pluck('node_id')->map(fn ($id) => (int) $id)->all());
        $this->assertNotNull($fresh->refresh()->onboarded_at);

        // Skipping the step leaves the choice as it is; saving with nothing ticked stops following.
        $this->post(route('welcome.save'), ['skip' => 1])->assertRedirect(route('welcome.profile'));
        $this->assertSame(1, DB::table('node_watches')->where('user_id', $fresh->id)->count());
        $this->post(route('welcome.save'), [])->assertRedirect(route('welcome.profile'));
        $this->assertSame(0, DB::table('node_watches')->where('user_id', $fresh->id)->count());
        $this->post(route('welcome.save'), ['forums' => [$this->forum->id]]);

        // ---- Step 2: the profile ---------------------------------------------------------------------
        Storage::fake('public');
        $this->get(route('welcome.profile'))->assertOk()->assertSee('Kendinizi tanıtın')->assertSee('name="avatar"', false)->assertSee('name="about"', false)
            ->assertSee(route('welcome'), false)->assertDontSee('Hangi forumları takip etmek istersiniz?');
        $this->post(route('welcome.profile.save'), ['skip' => 1, 'about' => 'Yazılmasın'])->assertRedirect(route('welcome.done'));
        $this->assertNull($fresh->refresh()->about);
        $this->post(route('welcome.profile.save'), ['avatar' => UploadedFile::fake()->create('belge.pdf', 20, 'application/pdf')])->assertSessionHasErrors('avatar');
        $this->post(route('welcome.profile.save'), [
            'avatar' => UploadedFile::fake()->image('ben.png', 200, 200), 'about' => 'Hosting işiyle uğraşıyorum.', 'location' => 'Ankara',
        ])->assertRedirect(route('welcome.done'));
        $fresh->refresh();
        $this->assertSame(['Hosting işiyle uğraşıyorum.', 'Ankara'], [$fresh->about, $fresh->location]);
        Storage::disk('public')->assertExists($fresh->avatar_path);
        // Saving again without a picture keeps the one already there.
        $this->post(route('welcome.profile.save'), ['about' => 'Hosting ve SEO.', 'location' => 'Ankara'])->assertRedirect(route('welcome.done'));
        $this->assertNotNull($fresh->refresh()->avatar_path);

        // ---- Step 3: what is set, what is left -------------------------------------------------------
        $this->get(route('welcome.done'))->assertOk()->assertSee('Hazırsınız')->assertSee('1 forumu takip ediyorsunuz')
            ->assertSee('%60')->assertSee('E-posta adresinizi doğrulayın')->assertSee('Foruma git')->assertSee(route('forum.index'), false);

        // The meter follows what the member fills in.
        $this->assertSame(60, WelcomeController::percent($fresh));
        $fresh->forceFill(['email_verified_at' => now()])->save();
        $this->assertSame(80, WelcomeController::percent($fresh->refresh()));
        app(Settings::class)->set(['posting.flood_seconds' => 0]);
        app(Posting::class)->createThread($this->forum, $fresh, 'İlk konum', 'Merhaba forum');
        $this->assertSame(100, WelcomeController::percent($fresh->refresh()));
        $this->get('/')->assertOk()->assertDontSee('profile-meter', false);
    }

    public function test_the_start_of_a_thread_opens_under_its_title(): void
    {
        app(Settings::class)->set(['posting.flood_seconds' => 0]);
        $thread = app(Posting::class)->createThread($this->forum, $this->member, 'Hosting önerisi arıyorum',
            "[b]Merhaba[/b], aylık 50 bin ziyaretçili bir site için [url=https://ornek.com]hosting[/url] arıyorum.\n[quote]eski alıntı metni[/quote]\n".str_repeat('Uzun bir açıklama. ', 40));

        // The lists carry what the script needs; the panel itself is fetched on demand.
        $this->get('/')->assertOk()->assertSee('data-thread-preview', false);
        $this->get(route('nodes.show', $this->forum))->assertOk()->assertSee('class="thread-title"', false);

        $views = $thread->refresh()->view_count;
        $this->get(route('threads.preview', $thread))->assertOk()
            ->assertSee('thread-preview', false)->assertSee('Hosting önerisi arıyorum')->assertSee('aylık 50 bin ziyaretçili bir site için')
            ->assertSee($this->member->username)->assertSee($this->forum->title)
            ->assertDontSee('[b]', false)->assertDontSee('eski alıntı metni')->assertDontSee('ornek.com')->assertSee('…');
        // Looking at the panel is not a view of the thread.
        $this->assertSame($views, $thread->refresh()->view_count);

        // A thread the visitor may not see has no panel either.
        $private = Node::create(['type' => 'forum', 'title' => 'Gizli Oda', 'parent_id' => $this->forum->parent_id]);
        foreach (['guest', 'registered'] as $group) {
            NodePermission::create(['node_id' => $private->id, 'user_group_id' => UserGroup::system($group)->id, 'permissions' => ['node.view' => 'never']]);
        }
        app(PermissionResolver::class)->flush();
        app(NodeTree::class)->flush();
        $hidden = app(Posting::class)->createThread($private, User::factory()->inGroup('admin')->create(), 'Gizli konu', 'Kimse görmesin');
        $this->assertContains($this->get(route('threads.preview', $hidden))->status(), [302, 403, 404]);
        $this->assertContains($this->actingAs($this->member)->get(route('threads.preview', $hidden))->status(), [403, 404]);
        $this->get(route('threads.preview', $hidden))->assertDontSee('Kimse görmesin');

        // A member who ignores the author gets no text; the administrator can switch the panels off.
        $reader = User::factory()->create();
        DB::table('user_ignores')->insert(['user_id' => $reader->id, 'ignored_id' => $this->member->id, 'created_at' => now()]);
        $this->actingAs($reader)->get(route('threads.preview', $thread))->assertOk()->assertSee('Bu konuyu açan üyeyi yok sayıyorsunuz.')->assertDontSee('aylık 50 bin');
        app(Settings::class)->set(['board.thread_preview' => false]);
        $this->get(route('threads.preview', $thread))->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee('data-thread-preview', false);
    }

    public function test_site_addresses_are_kept_out_of_the_profile_text(): void
    {
        $guard = new \App\Forum\Security\LinkGuard;
        foreach ([
            'webdanismani.com', 'Sitem: WebDanismani.COM', 'www.site.net', 'https://ornek.dev/sayfa', 'webdanismani . com', 'webdanismani .com',
            'webdanismani. com', 'webdanismani com', 'webdanismani com tr', 'site net tr', 'webdanismani nokta com', 'webdanismani (nokta) com',
            'site[dot]com', 'w e b d a n i s m a n i . c o m', 'site,com', 'www site com', 'bana yazın: ad@gmail.com', '185.12.34.56 sunucum',
            'şeker.com.tr', "site\u{200B}.com", 'İLAN.COM',
        ] as $text) {
            $this->assertNotNull($guard->find($text), 'should be refused: '.$text);
        }
        foreach ([
            'Bursa', 'Hosting ve SEO ile uğraşıyorum.', 'ASP.NET ve Node.js geliştiricisiyim.', 'İşim bitti. Net bir şey söyleyemem.', 'Geldik. Biz de buradayız.',
            'Saat 5.de buluşalım', 'Sürüm 8.3 kullanıyorum', 'Merhaba.Ben Ahmet', 'index.php dosyası', 'www nedir bilmiyorum', 'Ankara / Türkiye', null,
        ] as $text) {
            $this->assertNull($guard->find($text), 'should pass: '.$text);
        }
        $this->assertNull($guard->find('forum.ornek.com üyesiyim, https://ornek.com/a', ['ornek.com']));
        $this->assertSame('baska.com', $guard->find('ornek.com ve baska.com', ['ornek.com']));

        // ---- The account page ----------------------------------------------------------------------
        $this->actingAs($this->member)->get(route('account.edit'))->assertOk()->assertSee('Bu alana site adresi yazılamaz.');
        $this->put(route('account.profile'), ['about' => 'Sitem: webdanismani . com', 'location' => 'Bursa'])->assertSessionHasErrors('about');
        $this->put(route('account.profile'), ['about' => 'Hosting işi yapıyorum.', 'location' => 'site nokta com'])->assertSessionHasErrors('location');
        $this->assertNull($this->member->refresh()->about);
        $this->put(route('account.profile'), ['about' => 'Hosting işi yapıyorum. ASP.NET bilirim.', 'location' => 'Bursa'])->assertSessionHasNoErrors();
        $this->assertSame('Bursa', $this->member->refresh()->location);

        // ---- The welcome step --------------------------------------------------------------------------
        $this->post(route('welcome.profile.save'), ['about' => 'bana ulaşın www.sitem.net'])->assertSessionHasErrors('about');
        $this->get(route('welcome.profile'))->assertOk()->assertSee('Bu alana site adresi yazılamaz.');

        // The forum's own address and the ones the administrator lists are fine.
        $own = parse_url(config('app.url'), PHP_URL_HOST);
        $this->put(route('account.profile'), ['about' => "Eski üyeyim: {$own}/members"])->assertSessionHasNoErrors();
        $this->put(route('account.profile'), ['about' => 'Kaynak: wikipedia.org'])->assertSessionHasErrors('about');
        app(Settings::class)->set(['profile.allowed_domains' => "wikipedia.org\ngithub.com"]);
        $this->put(route('account.profile'), ['about' => 'Kaynak: tr.wikipedia.org, kodlarım github.com/ben'])->assertSessionHasNoErrors();

        // A group with the permission writes what it likes; the hint is gone for it.
        $moderator = User::factory()->inGroup('moderator')->create();
        $this->actingAs($moderator)->put(route('account.profile'), ['about' => 'Sitem: webdanismani.com'])->assertSessionHasNoErrors();
        $this->assertSame('Sitem: webdanismani.com', $moderator->refresh()->about);
        $this->get(route('account.edit'))->assertOk()->assertDontSee('Bu alana site adresi yazılamaz.');
    }

    public function test_thread_titles_start_every_word_with_a_capital_and_posts_have_a_reply_button(): void
    {
        // The suite runs with the tidying off (other tests check titles as typed); a board has it on unless switched off.
        app(Settings::class)->set(['posting.flood_seconds' => 0, 'posting.title_case' => true]);
        $this->assertSame('İlan: İstanbul İçi Kurye', \App\Forum\Titles::tidy('ilan: istanbul içi kurye'));
        $this->assertSame('Örnek Test Konusu Isparta', \App\Forum\Titles::tidy('ÖRNEK TEST KONUSU ISPARTA'));
        $this->assertSame('iPhone 15 İçin WordPress Eklentisi', \App\Forum\Titles::tidy('iPhone 15 için WordPress eklentisi'));
        $this->assertSame('SEO Nedir, Nasıl Yapılır?', \App\Forum\Titles::tidy('SEO nedir,  nasıl yapılır?'));
        $this->assertSame('E-Ticaret Sitesi (Acil)', \App\Forum\Titles::tidy('e-ticaret sitesi (acil)'));

        // A new thread, and an edited title.
        $this->actingAs($this->member)->get(route('threads.create', $this->forum))->assertOk()->assertSee('data-title-case', false);
        $this->post(route('threads.store', $this->forum), ['title' => 'örnek test konusu', 'body' => 'Bu bir deneme mesajıdır.'])->assertSessionHasNoErrors();
        $thread = Thread::where('user_id', $this->member->id)->latest('id')->first();
        $this->assertSame('Örnek Test Konusu', $thread->title);
        $this->put(route('posts.update', $thread->first_post_id), ['title' => 'yeni başlık yazdım', 'body' => 'Bu bir deneme mesajıdır.'])->assertSessionHasNoErrors();
        $this->assertSame('Yeni Başlık Yazdım', $thread->refresh()->title);

        // Switched off: titles stay as typed.
        app(Settings::class)->set(['posting.title_case' => false]);
        $this->post(route('threads.store', $this->forum), ['title' => 'küçük harfli başlık', 'body' => 'Bu bir deneme mesajıdır.'])->assertSessionHasNoErrors();
        $this->assertSame('küçük harfli başlık', Thread::latest('id')->first()->title);
        $this->get(route('threads.create', $this->forum))->assertOk()->assertDontSee('data-title-case', false);

        // ---- "Reply" under a post: the post arrives in the editor as a quote ---------------------------
        $other = User::factory()->create(['username' => 'cevapci']);
        $this->actingAs($other)->get(route('threads.show', $thread))->assertOk()
            ->assertSee('data-reply="'.route('posts.quote', $thread->first_post_id).'"', false)->assertSee('Bu mesajı alıntılayarak cevap yaz');
        $this->getJson(route('posts.quote', $thread->first_post_id))->assertOk()->assertJsonStructure(['bbcode']);
        $this->post(route('logout'));
        $this->get(route('threads.show', $thread))->assertOk()->assertDontSee('data-reply=', false);

        // ---- The author panel: counts, "thread starter" on the starter's later posts, online with the device ----
        $this->get(route('threads.show', $thread))->assertOk()->assertSee('post-counts', false)->assertDontSee('Konu Sahibi');
        app(Posting::class)->reply($thread, $other, 'Ben de aynı sorunu yaşıyorum.');
        app(Posting::class)->reply($thread, $this->member, 'Teşekkürler, çözüldü.');
        $this->member->forceFill(['last_seen_at' => now(), 'signed_out_at' => null, 'last_device' => 'mobile', 'location' => 'Bursa'])->save();
        $page = $this->get(route('threads.show', $thread))->assertOk()->assertSee('Konu Sahibi')->assertSee('presence on', false)->assertSee('is-online', false)->assertSee('Bursa');
        $this->assertSame(1, substr_count($page->getContent(), 'class="post-starter"'));
        $this->assertGreaterThanOrEqual(2, substr_count($page->getContent(), 'class="presence on"')); // both posts of the starter
        // Nobody online: the pills say so, with the time the member was last here.
        $this->member->forceFill(['last_seen_at' => now()->subHour()])->save();
        $other->forceFill(['last_seen_at' => now()->subHour()])->save();
        $this->get(route('threads.show', $thread))->assertOk()->assertDontSee('presence on', false)->assertSee('presence off', false)->assertSee('Çevrimdışı')->assertSee('is-offline', false);
    }

    public function test_user_groups_have_banner_designs_and_titles_show_the_step_on_the_ladder(): void
    {
        app(Settings::class)->set(['posting.flood_seconds' => 0]);
        $admin = User::factory()->inGroup('admin')->create(['username' => 'yonetici']);
        $vip = UserGroup::create(['name' => 'VIP Üye', 'badge' => 'VIP', 'color' => '#b7791f', 'priority' => 20, 'banner_style' => 'ribbon', 'banner_icon' => 'gem', 'banner_color2' => '#7c3aed']);
        $this->member->groups()->attach($vip->id);
        \App\Models\UserTitle::query()->delete();
        foreach ([0 => 'Yeni Üye', 10 => 'Üye', 100 => 'Aktif Üye', 1000 => 'Usta'] as $posts => $title) {
            \App\Models\UserTitle::create(['min_posts' => $posts, 'title' => $title]);
        }
        app(\App\Forum\Rewards::class)->flush();

        // The ladder: where a post count stands and what comes next.
        $this->assertSame(['title' => 'Yeni Üye', 'level' => 1, 'total' => 4, 'next' => ['title' => 'Üye', 'posts' => 10], 'color' => null, 'icon' => null], app(\App\Forum\Rewards::class)->rank(3));
        $this->assertSame(['title' => 'Usta', 'level' => 4, 'total' => 4, 'next' => null, 'color' => null, 'icon' => null], app(\App\Forum\Rewards::class)->rank(5000));

        $thread = app(Posting::class)->createThread($this->forum, $this->member, 'Afiş denemesi', 'Merhaba');
        app(Posting::class)->reply($thread, $admin, 'Hoş geldiniz.');
        $page = $this->get(route('threads.show', $thread))->assertOk()
            // The member: the title with its steps, and the banner of the paid group in its own design.
            ->assertSee('class="rank r1"', false)->assertSee('Sonraki unvan: Üye (10 mesaj)')->assertSee('rank-pips', false)
            ->assertSee('gbanner gb-ribbon block', false)->assertSee('--c1: #b7791f; --c2: #7c3aed', false)->assertSee('VIP')
            // The administrator: the staff banner with its icon; no title of the ladder next to it.
            ->assertSee('gbanner gb-plate block', false)->assertSee('Yönetici');
        $this->assertSame(1, substr_count($page->getContent(), 'class="rank '));
        $this->assertSame(2, substr_count($page->getContent(), 'class="gb-icon"'));

        // Profile and member card use the same pieces.
        $this->get(route('members.show', $this->member))->assertOk()->assertSee('gbanner gb-ribbon', false)->assertSee('rank inline', false);
        $this->get(route('members.card', $admin))->assertOk()->assertSee('gbanner gb-plate', false);

        // ---- The administrator chooses the design ------------------------------------------------------
        $this->actingAs($admin)->get(route('admin.groups.edit', $vip))->assertOk()
            ->assertSee('Afiş tasarımı')->assertSee('data-banner-form', false)->assertSee('Kurdele')->assertSee('Parlayan')->assertSee('name="banner_icon"', false);
        $form = ['name' => 'VIP Üye', 'badge' => 'VIP', 'color' => '#b7791f', 'priority' => 20];
        $this->put(route('admin.groups.update', $vip), $form + ['banner_style' => 'uydurma'])->assertSessionHasErrors('banner_style');
        $this->put(route('admin.groups.update', $vip), $form + ['banner_style' => 'shine', 'banner_icon' => 'yok-boyle-simge'])->assertSessionHasErrors('banner_icon');
        $this->put(route('admin.groups.update', $vip), $form + ['banner_style' => 'shine', 'banner_icon' => 'crown', 'banner_color2' => 'kirmizi'])->assertSessionHasErrors('banner_color2');
        $this->put(route('admin.groups.update', $vip), $form + ['banner_style' => 'shine', 'banner_icon' => 'crown', 'banner_color2' => ''])->assertSessionHasNoErrors();
        $this->assertSame(['shine', 'crown', null], [$vip->refresh()->banner_style, $vip->banner_icon, $vip->banner_color2]);
        $this->get(route('threads.show', $thread))->assertOk()->assertSee('gbanner gb-shine block', false)->assertDontSee('gb-ribbon', false);

        // A group without a design gets the standard one; a colour that is not a colour never reaches the page.
        $vip->forceFill(['banner_style' => null, 'color' => 'red;background:url(x)'])->save();
        $this->assertSame('plate', $vip->bannerStyle());
        $this->assertSame('', $vip->bannerVars());
    }

    public function test_members_see_their_open_sessions_and_hear_about_new_devices(): void
    {
        $this->member->forceFill(['password' => bcrypt('gizli-parola-1')])->save();
        $chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
        $iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
        $this->assertSame(['device' => 'desktop', 'browser' => 'Chrome', 'system' => 'Windows'], \App\Forum\Security\Devices::describe($chrome));
        $this->assertSame(['device' => 'mobile', 'browser' => 'Safari', 'system' => 'iOS'], \App\Forum\Security\Devices::describe($iphone));

        // The first sign-in notes the browser and says nothing; the browser keeps its mark in a cookie.
        $first = $this->withHeader('User-Agent', $chrome)->post(route('login'), ['login' => $this->member->username, 'password' => 'gizli-parola-1']);
        $first->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('user_devices')->where('user_id', $this->member->id)->count());
        $this->assertSame(0, $this->member->notifications()->count());
        $token = DB::table('user_devices')->value('token');
        $first->assertCookie(\App\Forum\Security\Devices::COOKIE, $token);
        $this->post(route('logout'));

        // The same browser again: still one row, still no alert.
        $this->withCookie(\App\Forum\Security\Devices::COOKIE, $token)->withHeader('User-Agent', $chrome)->post(route('login'), ['login' => $this->member->username, 'password' => 'gizli-parola-1']);
        $this->assertSame(1, DB::table('user_devices')->where('user_id', $this->member->id)->count());
        $this->assertSame(0, $this->member->notifications()->count());
        $this->post(route('logout'));

        // Another browser: a second row and an alert that names it.
        $this->withUnencryptedCookies([])->withCookie(\App\Forum\Security\Devices::COOKIE, '')->withHeader('User-Agent', $iphone)->post(route('login'), ['login' => $this->member->username, 'password' => 'gizli-parola-1']);
        $this->assertSame(2, DB::table('user_devices')->where('user_id', $this->member->id)->count());
        $alert = $this->member->notifications()->first();
        $this->assertSame('new_device', $alert->data['kind']);
        $this->assertStringContainsString('Safari / iOS', $alert->data['text']);

        // Switched off by the administrator: the browser is still noted, without an alert.
        $this->post(route('logout'));
        app(Settings::class)->set(['security.new_device_alerts' => false]);
        $this->withCookie(\App\Forum\Security\Devices::COOKIE, '')->withHeader('User-Agent', 'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0')->post(route('login'), ['login' => $this->member->username, 'password' => 'gizli-parola-1']);
        $this->assertSame(3, DB::table('user_devices')->where('user_id', $this->member->id)->count());
        $this->assertSame(1, $this->member->notifications()->count());

        // ---- The list on the account page -------------------------------------------------------------
        $this->actingAs($this->member);
        $other = User::factory()->create();
        $rows = [
            ['id' => 'oturum-telefon', 'user_id' => $this->member->id, 'ip_address' => '85.100.1.2', 'user_agent' => $iphone, 'payload' => '', 'last_activity' => now()->subHours(3)->getTimestamp()],
            ['id' => 'oturum-eski-pc', 'user_id' => $this->member->id, 'ip_address' => '85.100.1.3', 'user_agent' => $chrome, 'payload' => '', 'last_activity' => now()->subDay()->getTimestamp()],
            ['id' => 'baskasinin', 'user_id' => $other->id, 'ip_address' => '85.100.9.9', 'user_agent' => $chrome, 'payload' => '', 'last_activity' => now()->getTimestamp()],
        ];
        DB::table('sessions')->insert($rows);
        $key = \App\Forum\Security\Devices::key('oturum-telefon');
        $this->get(route('account.edit'))->assertOk()->assertSee('Açık oturumlar')->assertSee('Safari · iOS')->assertSee('85.100.1.2')
            ->assertSee(route('account.sessions.end', $key), false)->assertSee('Diğer tüm oturumları kapat')
            ->assertDontSee('oturum-telefon')->assertDontSee('85.100.9.9');

        // Closing one session; somebody else's cannot be closed, and the id itself is not accepted.
        $this->delete(route('account.sessions.end', \App\Forum\Security\Devices::key('baskasinin')))->assertSessionHas('error');
        $this->assertSame(3, DB::table('sessions')->count());
        $before = $this->member->refresh()->remember_token;
        $this->delete(route('account.sessions.end', $key))->assertSessionHas('success');
        $this->assertSame(['oturum-eski-pc', 'baskasinin'], DB::table('sessions')->orderBy('user_id')->pluck('id')->all());
        $this->assertNotSame($before, $this->member->refresh()->remember_token, '"Remember me" cookies of the closed browser are worthless now.');

        // Closing all the others asks for the password.
        $this->delete(route('account.sessions.end-others'), ['current_password' => 'yanlis'])->assertSessionHasErrors('current_password');
        $this->delete(route('account.sessions.end-others'), ['current_password' => 'gizli-parola-1'])->assertSessionHas('success');
        $this->assertSame(['baskasinin'], DB::table('sessions')->pluck('id')->all());
    }

    public function test_accounts_that_share_a_browser_or_an_address_are_pointed_out_to_the_staff(): void
    {
        $admin = User::factory()->inGroup('admin')->create(['username' => 'yonetici']);
        $second = User::factory()->create(['username' => 'ikinci_hesap']);
        $third = User::factory()->create(['username' => 'ucuncu_hesap']);
        $neighbour = User::factory()->create(['username' => 'komsu']);
        $stranger = User::factory()->create(['username' => 'alakasiz']);
        $device = fn (User $user, string $token) => DB::table('user_devices')->insert(['user_id' => $user->id, 'token' => $token, 'created_at' => now(), 'last_seen_at' => now()]);
        $ip = fn (User $user, string $address) => DB::table('ip_logs')->insert(['user_id' => $user->id, 'ip' => $address, 'action' => 'login', 'created_at' => now()]);

        // member + second share one browser, second + third another: one chain of three. The neighbour only shares an address.
        $device($this->member, str_repeat('a', 40));
        $device($second, str_repeat('a', 40));
        $device($second, str_repeat('b', 40));
        $device($third, str_repeat('b', 40));
        $device($stranger, str_repeat('c', 40));
        $ip($this->member, '85.100.1.2');
        $ip($neighbour, '85.100.1.2');
        $ip($stranger, '85.100.7.7');
        // A crowded address (a mobile network) proves nothing.
        foreach (User::factory()->count(\App\Forum\Security\Duplicates::CROWD)->create() as $many) {
            $ip($many, '10.0.0.1');
        }
        $ip($this->member, '10.0.0.1');

        $duplicates = app(\App\Forum\Security\Duplicates::class);
        $groups = $duplicates->byDevice();
        $this->assertCount(1, $groups);
        $this->assertSame([$this->member->username, 'ikinci_hesap', 'ucuncu_hesap'], $groups[0]['users']->pluck('username')->all());
        $this->assertSame(['85.100.1.2'], array_column($duplicates->byIp(), 'ip'));

        $linked = collect($duplicates->linkedTo($this->member))->mapWithKeys(fn ($link) => [$link['user']->username => [$link['device'], $link['ips']]])->all();
        $this->assertSame(['ikinci_hesap' => [true, 0], 'komsu' => [false, 1]], $linked);
        $this->assertSame([], $duplicates->linkedTo($stranger));

        // The pages: administrators only.
        $this->actingAs($this->member)->get(route('admin.duplicates'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.duplicates'))->assertOk()
            ->assertSee('Çoklu hesaplar')->assertSee('ikinci_hesap')->assertSee('ucuncu_hesap')->assertSee('85.100.1.2')->assertSee('komsu')
            ->assertDontSee('alakasiz')->assertDontSee('10.0.0.1');
        $this->get(route('admin.users.edit', $this->member))->assertOk()->assertSee('Bu hesapla bağlantılı hesaplar')->assertSee('ikinci_hesap')->assertSee('Aynı tarayıcı')->assertSee('1 ortak IP adresi');
        $this->get(route('admin.users.edit', $stranger))->assertOk()->assertDontSee('Bu hesapla bağlantılı hesaplar');

        // On the profile the staff sees the names, members see nothing of it.
        $moderator = User::factory()->inGroup('moderator')->create();
        $this->actingAs($moderator)->get(route('members.show', $this->member))->assertOk()->assertSee('Aynı kişi olabilir')->assertSee('ikinci_hesap');
        $this->actingAs($second)->get(route('members.show', $this->member))->assertOk()->assertDontSee('Aynı kişi olabilir');
    }

    public function test_the_administrator_makes_and_downloads_backups(): void
    {
        $admin = User::factory()->inGroup('admin')->create();
        $backups = app(\App\Forum\Backups::class);
        foreach ($backups->all() as $old) {
            $backups->delete($old['name']);
        }

        $this->actingAs($this->member)->get(route('admin.backups'))->assertForbidden();
        $this->post(route('admin.backups.store'), ['kind' => 'db'])->assertForbidden();
        $this->actingAs($admin)->get(route('admin.backups'))->assertOk()->assertSee('Veritabanını şimdi yedekle')->assertSee('Henüz yedek alınmadı.')->assertSee('Otomatik yedekleme kapalı.');
        $this->post(route('admin.backups.store'), ['kind' => 'baska', 'current_password' => 'password'])->assertSessionHasErrors('kind');

        // A backup is the whole board in one file: making one asks for the password again.
        $this->post(route('admin.backups.store'), ['kind' => 'db'])->assertSessionHasErrors('current_password');
        $this->post(route('admin.backups.store'), ['kind' => 'db', 'current_password' => 'yanlis'])->assertSessionHasErrors('current_password');
        $this->assertCount(0, $backups->all());

        // The database (a file database in the tests: its compressed copy).
        $this->post(route('admin.backups.store'), ['kind' => 'db', 'current_password' => 'password'])->assertSessionHas('success');
        $made = $backups->all();
        $this->assertCount(1, $made);
        $this->assertSame('db', $made[0]['kind']);
        $this->assertGreaterThan(100, $made[0]['size']);
        $this->get(route('admin.backups'))->assertOk()->assertSee($made[0]['name'])->assertSee('İndir');
        // Fetching it asks for the password as well; a plain link does nothing.
        $this->get(route('admin.backups.download', $made[0]['name']))->assertStatus(405);
        $this->post(route('admin.backups.download', $made[0]['name']))->assertSessionHasErrors('current_password');
        $this->post(route('admin.backups.download', $made[0]['name']), ['current_password' => 'password'])->assertOk()->assertDownload($made[0]['name']);

        // The uploaded files.
        Storage::disk('public')->put('avatars/yedek-denemesi.txt', 'içerik');
        $this->post(route('admin.backups.store'), ['kind' => 'files', 'current_password' => 'password'])->assertSessionHas('success');
        $files = collect($backups->all())->firstWhere('kind', 'files');
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($backups->path($files['name'])));
        $this->assertSame('içerik', $zip->getFromName('public/avatars/yedek-denemesi.txt'));
        $zip->close();
        Storage::disk('public')->delete('avatars/yedek-denemesi.txt');

        // Names are checked: nothing outside the backup folder can be asked for.
        $this->post('/admin/backups/..%2F..%2F.env', ['current_password' => 'password'])->assertNotFound();
        $this->post(route('admin.backups.download', 'db-20260101-000000-aaaaaaaaaa.sql.gz'), ['current_password' => 'password'])->assertNotFound();
        $this->actingAs($this->member)->post(route('admin.backups.download', $made[0]['name']), ['current_password' => 'password'])->assertForbidden();

        // Automatic: only when asked for, only when the newest one is old enough, and the oldest go.
        $this->assertNull($backups->auto());
        app(Settings::class)->set(['backup.auto_days' => 1, 'backup.keep' => 1]);
        $this->assertNull($backups->auto(), 'The backup made a moment ago is new enough.');
        touch($backups->path($made[0]['name']), now()->subDays(2)->getTimestamp());
        $this->assertNotNull($auto = $backups->auto());
        $this->assertSame([$auto], collect($backups->all())->where('kind', 'db')->pluck('name')->all());

        $this->actingAs($admin)->delete(route('admin.backups.destroy', $auto))->assertSessionHas('success');
        $backups->delete($files['name']);
        $this->assertSame([], $backups->all());
    }

    public function test_a_forum_can_give_new_threads_a_starting_text_and_ask_for_a_prefix(): void
    {
        app(Settings::class)->set(['posting.flood_seconds' => 0]);
        $admin = User::factory()->inGroup('admin')->create();
        $prefix = \App\Models\ThreadPrefix::first() ?? \App\Models\ThreadPrefix::create(['title' => 'Satılık', 'color' => 'green']);
        $template = "[b]Fiyat:[/b] \n[b]Durum:[/b] \n[b]Teslimat:[/b] ";

        // The administrator sets both on the forum.
        $this->actingAs($admin)->get(route('admin.nodes.edit', $this->forum))->assertOk()->assertSee('Yeni konunun başlangıç metni (şablon)')->assertSee('Yeni konuda ön ek zorunlu olsun');
        $this->put(route('admin.nodes.update', $this->forum), ['title' => $this->forum->title, 'type' => 'forum', 'parent_id' => $this->forum->parent_id, 'is_active' => 1, 'thread_template' => $template, 'require_prefix' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($this->forum->refresh()->require_prefix);
        $this->assertSame(trim($template), $this->forum->thread_template);

        // The member finds the text in the editor and has to choose a prefix.
        $this->actingAs($this->member)->get(route('threads.create', $this->forum))->assertOk()
            ->assertSee('[b]Fiyat:[/b]', false)->assertSee('data-prefilled', false)->assertSee('Seçin…');
        $form = ['title' => 'Satılık alan adı', 'body' => "[b]Fiyat:[/b] 500 TL\n[b]Durum:[/b] Sıfır\n[b]Teslimat:[/b] Aynı gün", 'prefix_id' => $prefix->id];
        $this->post(route('threads.store', $this->forum), ['prefix_id' => ''] + $form)->assertSessionHasErrors('prefix_id');
        $this->post(route('threads.store', $this->forum), ['body' => $template] + $form)->assertSessionHasErrors('body');
        $this->post(route('threads.store', $this->forum), ['body' => "  [b]Fiyat:[/b]\n\n[b]Durum:[/b]\n[b]Teslimat:[/b]"] + $form)->assertSessionHasErrors('body');
        $this->post(route('threads.store', $this->forum), $form)->assertSessionHasNoErrors();
        $this->assertSame($prefix->id, Thread::latest('id')->first()->prefix_id);

        // Other forums stay as they were.
        $plain = Node::create(['type' => 'forum', 'title' => 'Serbest Kürsü', 'parent_id' => $this->forum->parent_id]);
        app(NodeTree::class)->flush();
        app(PermissionResolver::class)->flush();
        $this->get(route('threads.create', $plain))->assertOk()->assertDontSee('data-prefilled', false)->assertDontSee('Seçin…');
        $this->post(route('threads.store', $plain), ['title' => 'Serbest konu', 'body' => 'Ön eksiz de olur.'])->assertSessionHasNoErrors();
    }

    public function test_steps_of_the_title_ladder_have_their_own_colour_and_icon(): void
    {
        $admin = User::factory()->inGroup('admin')->create();
        \App\Models\UserTitle::query()->delete();
        $step = \App\Models\UserTitle::create(['min_posts' => 0, 'title' => 'Çaylak']);
        \App\Models\UserTitle::create(['min_posts' => 50, 'title' => 'Kıdemli']);
        app(\App\Forum\Rewards::class)->flush();

        $this->actingAs($admin)->get(route('admin.profile'))->assertOk()->assertSee('name="color"', false)->assertSee('name="icon"', false)->assertSee('Görünüm');
        $this->post(route('admin.profile.titles.save'), ['id' => $step->id, 'min_posts' => 0, 'title' => 'Çaylak', 'color' => 'mavi'])->assertSessionHasErrors('color');
        $this->post(route('admin.profile.titles.save'), ['id' => $step->id, 'min_posts' => 0, 'title' => 'Çaylak', 'icon' => 'uydurma'])->assertSessionHasErrors('icon');
        $this->post(route('admin.profile.titles.save'), ['id' => $step->id, 'min_posts' => 0, 'title' => 'Çaylak', 'color' => '#7c3aed', 'icon' => 'flame'])->assertSessionHasNoErrors();

        $rank = app(\App\Forum\Rewards::class)->rank(3);
        $this->assertSame(['#7c3aed', 'flame'], [$rank['color'], $rank['icon']]);
        $this->assertNull(app(\App\Forum\Rewards::class)->rank(60)['color']);

        // On the member's profile the chip carries the colour and the icon.
        $this->member->forceFill(['post_count' => 3, 'custom_title' => null])->save();
        $this->get(route('members.show', $this->member))->assertOk()->assertSee('--rank: #7c3aed', false)->assertSee('Çaylak');
        $this->get(route('admin.profile'))->assertOk()->assertSee('--rank: #7c3aed', false);
    }

    public function test_editing_an_approved_post_does_not_send_it_back_to_the_queue(): void
    {
        app(Settings::class)->set(['posting.flood_seconds' => 0, 'spam.link_min_posts' => 5]);
        $admin = User::factory()->inGroup('admin')->create();
        $this->forum->forceFill(['require_approval' => true])->save();
        app(NodeTree::class)->flush();

        // A new thread in a forum that approves everything waits for a moderator, as before.
        $this->actingAs($this->member)->post(route('threads.store', $this->forum), ['title' => 'Onay bekleyen konu', 'body' => 'İlk hali.'])->assertSessionHasNoErrors();
        $thread = Thread::latest('id')->first();
        $this->assertSame('moderated', $thread->state);
        app(Posting::class)->setThreadState($thread, 'visible', $admin);

        // Once approved, the author's edit stays visible — also with a link, which only counts when first posted.
        $this->put(route('posts.update', $thread->first_post_id), ['title' => 'Onay bekleyen konu', 'body' => 'Düzeltilmiş hali: https://ornek.com'])->assertSessionHas('success');
        $this->assertSame('visible', $thread->refresh()->state);
        $this->assertSame('Düzeltilmiş hali: https://ornek.com', $thread->firstPost->refresh()->body);
    }

    public function test_the_karbon_theme_is_a_clean_package_that_restyles_the_forum_in_both_modes(): void
    {
        if (! is_file(base_path('themes/build.php'))) {
            $this->markTestSkipped('The sold themes are not part of this repository.');
        }
        // Built from its sources, exactly as it is uploaded to the market.
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('themes/build.php')), $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
        $package = collect(glob(base_path('dist/themes/karbon-*.zip')))->last();
        $this->assertNotNull($package);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($package));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        sort($names);
        $this->assertSame(['LICENSE-BarlowCondensed.txt', 'assets/barlow-condensed-600-latin-ext.woff2', 'assets/barlow-condensed-600-latin.woff2', 'karbon.css', 'theme.json'], $names, 'A theme is its manifest, one stylesheet and the fonts that stylesheet uses.');
        $manifest = json_decode($zip->getFromName('theme.json'), true);
        $css = $zip->getFromName('karbon.css');
        $zip->close();

        $this->assertSame('WebDanismani/Karbon', $manifest['id']);
        $this->assertSame(array_keys(app(\App\Forum\Theme::class)->fields()), array_keys($manifest['colors']));
        foreach ($manifest['colors'] as $colour) {
            $this->assertTrue(\App\Forum\Theme::isHex($colour));
        }
        // The stylesheet survives the forum's handling untouched, is balanced, and brings its own dark mode.
        $this->assertStringNotContainsString('<', $css);
        $this->assertSame(substr_count($css, '{'), substr_count($css, '}'));
        $this->assertStringContainsString(':root[data-theme="dark"]', $css);
        $this->assertStringContainsString('prefers-color-scheme: dark', $css);
        $this->assertStringNotContainsString('@import', $css);
        preg_match_all('~url\("?([^)"]+)~', $css, $urls);
        $this->assertNotEmpty($urls[1]);
        foreach ($urls[1] as $url) {
            $this->assertStringStartsWith('theme://', $url, 'Nothing is loaded from elsewhere: only files the package brings.');
            $this->assertContains('assets/'.substr($url, 8), $names);
        }
        // It changes the page itself, not only its colours: boxed sheet, tabs, separate category blocks, dark author panel.
        foreach (['main.container', '.block:has(> .catbar)', 'attr(data-label)', '.post-user {', 'Barlow Condensed', '@media (max-width: 720px)'] as $piece) {
            $this->assertStringContainsString($piece, $css);
        }
        // The colours the administrator can change are left to the administrator in the light mode.
        preg_match('/^:root \{(.*?)^\}/ms', $css, $light);
        foreach (['--accent:', '--header:', '--bar:', '--bg:'] as $token) {
            $this->assertStringNotContainsString($token, $light[1]);
        }

        // Installed and switched on: the page carries the colours and the stylesheet; switched off, neither.
        config(['forum.themes_path' => storage_path('framework/testing/themes-'.bin2hex(random_bytes(4)))]);
        $themes = app(\App\Forum\Themes::class);
        $this->assertSame(['id' => 'WebDanismani/Karbon', 'title' => 'Karbon', 'version' => $manifest['version']], $themes->installZip($package, 'WebDanismani/Karbon'));
        $this->get('/')->assertOk()->assertDontSee('id="theme-css"', false);
        // Karbon is sold: it is used only once its own licence code is active on the forum.
        $this->assertSame('wd-forum-tema-karbon', $manifest['license']);
        $this->assertFalse($themes->activate('WebDanismani/Karbon'));
        $this->fakeLicenseServer();
        $this->licenseFor('wd-forum-tema-karbon');
        $this->assertTrue($themes->activate('WebDanismani/Karbon'));
        $this->get('/')->assertOk()->assertSee('id="theme-css"', false)->assertSee('--k-shell', false)
            ->assertSee('--accent:'.$manifest['colors']['accent'], false)->assertSee('--accent-base:'.$manifest['colors']['accent'], false)
            ->assertSee('--on-accent-base:#ffffff', false)->assertSee('content="'.$manifest['colors']['header'].'"', false)
            ->assertSee('data-page="forum.index"', false)->assertSee('data-guest', false)
            ->assertDontSee('theme://', false)
            ->assertSee(url('theme-assets/WebDanismani/Karbon/barlow-condensed-600-latin.woff2').'?v='.$manifest['version'], false);

        // The fonts it brought are served from the forum itself; nothing else in that folder is.
        $font = $this->get('/theme-assets/WebDanismani/Karbon/barlow-condensed-600-latin-ext.woff2')->assertOk()->assertHeader('Content-Type', 'font/woff2');
        $this->assertStringContainsString('immutable', $font->headers->get('Cache-Control'));
        $this->get('/theme-assets/WebDanismani/Karbon/theme.json')->assertNotFound();
        $this->get('/theme-assets/WebDanismani/Karbon/missing.woff2')->assertNotFound();
        $this->get('/theme-assets/WebDanismani/Nothing/barlow-condensed-600-latin.woff2')->assertNotFound();

        // A package that hides anything other than a font or a picture among its assets is refused as a whole.
        $bad = storage_path('framework/testing/bad-theme-'.bin2hex(random_bytes(4)).'.zip');
        $zip = new \ZipArchive;
        $zip->open($bad, \ZipArchive::CREATE);
        $zip->addFromString('theme.json', json_encode(['id' => 'Acme/Bad', 'title' => 'Bad', 'version' => '1.0.0', 'css' => 'bad.css']));
        $zip->addFromString('bad.css', 'body{}');
        $zip->addFromString('assets/shell.php', 'not a font');
        $zip->close();
        $this->assertIsString($themes->installZip($bad));
        $this->assertArrayNotHasKey('Acme/Bad', $themes->all());
        @unlink($bad);

        // Another accent chosen under Appearance is kept: the theme builds on it.
        $colors = app(\App\Forum\Theme::class)->colors();
        app(\App\Forum\Theme::class)->save(['accent' => '#1f9d6b'] + $colors);
        $this->get('/')->assertOk()->assertSee('--accent:#1f9d6b', false)->assertSee('--accent-base:#1f9d6b', false)->assertSee('id="theme-css"', false);

        $themes->deactivate();
        $this->get('/')->assertOk()->assertDontSee('id="theme-css"', false)->assertDontSee('--accent:#1f9d6b', false);
        \Illuminate\Support\Facades\File::deleteDirectory(config('forum.themes_path'));
    }

    public function test_the_arena_theme_for_gaming_boards_brings_a_portal_home_page_with_its_own_header(): void
    {
        if (! is_file(base_path('themes/build.php'))) {
            $this->markTestSkipped('The sold themes are not part of this repository.');
        }
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('themes/build.php')), $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
        $package = collect(glob(base_path('dist/themes/arena-3*.zip')))->last();
        $this->assertNotNull($package);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($package));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $manifest = json_decode($zip->getFromName('theme.json'), true);
        $css = $zip->getFromName('arena.css');
        $zip->close();

        // Data only: the manifest, one stylesheet, licence texts, and fonts and pictures in "assets".
        foreach ($names as $name) {
            $this->assertMatchesRegularExpression('~^(theme\.json|arena\.css|LICENSE-[A-Za-z]+\.txt|assets/[a-z0-9-]+\.(woff2|webp))$~', $name);
        }
        $this->assertSame('WebDanismani/Arena', $manifest['id']);
        $this->assertSame(array_keys(app(\App\Forum\Theme::class)->fields()), array_keys($manifest['colors']));
        $this->assertSame('single', $manifest['header']);
        $this->assertStringNotContainsString('<', $css);
        $this->assertSame(substr_count($css, '{'), substr_count($css, '}'));
        $this->assertStringNotContainsString('@import', $css);
        // Nothing is loaded from elsewhere: every address is a file of the package or drawn inline.
        $this->assertSame(0, preg_match('~url\(\s*(?!["\']?(?:theme://|data:))~', $css));
        preg_match_all('~theme://([A-Za-z0-9._-]+)~', $css, $files);
        foreach (array_unique($files[1]) as $file) {
            $this->assertContains('assets/'.$file, $names);
        }
        foreach ($manifest['home']['slides'] as $slide) {
            $this->assertContains('assets/'.$slide['image'], $names);
        }
        foreach (['.hero-title', '.quick-tile', '.stat-band', '.news-card', '.node-counts span::before', ':root[data-theme="dark"]', '.block:has(> .catbar)', '@media (max-width: 720px)'] as $piece) {
            $this->assertStringContainsString($piece, $css);
        }

        config(['forum.themes_path' => storage_path('framework/testing/themes-'.bin2hex(random_bytes(4)))]);
        $themes = app(\App\Forum\Themes::class);
        $home = app(\App\Forum\Home::class);
        $this->assertSame(['id' => 'WebDanismani/Arena', 'title' => 'Arena', 'version' => $manifest['version']], $themes->installZip($package, 'WebDanismani/Arena'));

        // A sold theme needs its licence; the gate itself has tests of its own (LicenseTest).
        if (isset($manifest['license']) && method_exists($this, 'licenseFor')) {
            $this->fakeLicenseServer();
            $this->licenseFor($manifest['license']);
        }

        // Before: the classic page, with the menu strip under the header.
        $this->get('/')->assertOk()->assertSee('class="navbar"', false)->assertDontSee('class="hero"', false)->assertDontSee('data-header', false);

        // Switched on, the theme brings its header and the sections it was designed for, with its own starting texts.
        $this->assertTrue($themes->activate('WebDanismani/Arena'));
        $this->assertTrue($home->enabled('hero'));
        $this->assertFalse($home->enabled('latest'));
        $this->get('/')->assertOk()
            ->assertSee('data-header="single"', false)->assertDontSee('class="navbar"', false)
            ->assertSee('class="hero"', false)->assertSee('<em>Buluşma</em> Noktası', false)
            ->assertSee('theme-assets/WebDanismani/Arena/arena-hero.webp', false)
            ->assertSee('quick-tile qt-5', false)->assertSee('stat-band', false)->assertSee('promo promo-gold', false)
            ->assertSee('--a-navy', false)
            ->assertSee('--accent:'.$manifest['colors']['accent'], false);
        $this->get('/theme-assets/WebDanismani/Arena/arena-hero.webp')->assertOk()->assertHeader('Content-Type', 'image/webp');
        // Other pages keep the one-row header without the home page sections.
        $this->get(route('members.index'))->assertOk()->assertSee('data-header="single"', false)->assertDontSee('class="hero"', false);

        // What the administrator wrote is never replaced when the theme is switched on again.
        $slides = $home->slides();
        $slides[0]['title'] = 'Kendi başlığım';
        app(Settings::class)->set(['home.slides' => $slides]);
        app(\App\Forum\Home::class)->saveSections(['hero' => true]);
        $themes->deactivate();
        // Switched off, nothing of the theme stays behind: classic header, classic home page, default colours.
        $this->get('/')->assertOk()->assertDontSee('--a-navy', false)->assertSee('class="navbar"', false)
            ->assertDontSee('class="hero"', false)->assertDontSee('Kendi başlığım')->assertDontSee('stat-band', false)->assertSee('latest-grid', false)
            ->assertDontSee('--accent:'.$manifest['colors']['accent'], false);
        // Switched on again, texts and sections are as the administrator left them.
        $this->assertTrue($themes->activate('WebDanismani/Arena'));
        $this->assertSame('Kendi başlığım', app(\App\Forum\Home::class)->slides()[0]['title']);
        $this->assertTrue(app(\App\Forum\Home::class)->enabled('hero'));
        $this->assertFalse(app(\App\Forum\Home::class)->enabled('tiles'), 'Switching the theme on again does not switch sections back on that the administrator switched off.');

        $themes->deactivate();
        $this->get('/')->assertOk()->assertDontSee('id="theme-css"', false)->assertSee('class="navbar"', false);
        \Illuminate\Support\Facades\File::deleteDirectory(config('forum.themes_path'));
    }

    public function test_the_admin_panel_has_its_own_frame_menu_groups_and_start_page(): void
    {
        $admin = User::factory()->inGroup('admin')->create();
        $this->actingAs($admin);

        // Its own stylesheet and colours: board colours, an installed theme and custom CSS stay outside.
        app(\App\Forum\Theme::class)->save(['accent' => '#ff0000'] + app(\App\Forum\Theme::class)->colors());
        app(\App\Forum\Settings::class)->set(['theme.custom_css' => '.acp-marker { color: red; }']);
        $page = $this->get(route('admin.dashboard'))->assertOk()
            ->assertSee('assets/admin.css', false)->assertSee('class="acp"', false)
            ->assertDontSee('id="theme-colors"', false)->assertDontSee('id="theme-css"', false)->assertDontSee('.acp-marker', false)
            // Top bar, grouped menu, start page.
            ->assertSee('data-admin-search="/admin/search"', false)->assertSee('Forumu görüntüle')
            ->assertSee('data-acp-group="members"', false)->assertSee('data-acp-group="tools"', false)
            ->assertSee('Sizi bekleyenler')->assertSee('Bekleyen iş yok.')->assertSee('Kısayollar')->assertSee('Son 30 gün');
        $this->assertSame(0, substr_count($page->getContent(), 'data-here'), 'On the start page no group is the current one.');

        // The group of the page in view is open and named above the title.
        $page = $this->get(route('admin.groups.index'))->assertOk()->assertSee('acp-crumb', false);
        $this->assertMatchesRegularExpression('~data-acp-group="members"\s+open data-here~', $page->getContent());
        $this->assertSame(1, substr_count($page->getContent(), 'data-here'));

        // Something to do shows up in the bar, in the menu and on the start page.
        \App\Models\ContactMessage::create(['name' => 'Deneme', 'email' => 'deneme@example.com', 'subject' => 'Soru', 'body' => 'Merhaba', 'is_read' => false]);
        $pending = app(\App\Forum\Setup\Pending::class);
        $this->assertSame(1, $pending->total());
        $this->assertSame(1, $pending->forRoute('admin.messages'));
        $this->assertSame(['messages'], array_keys($pending->waiting()));
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('Okunmamış iletişim mesajları')->assertSee('class="acp-count">1', false)->assertDontSee('Bekleyen iş yok.');

        // The long settings form lists its sections beside it.
        $this->get(route('admin.settings.edit'))->assertOk()->assertSee('data-acp-sections', false)->assertSee('acp-save', false);
    }

    public function test_the_forum_package_leaves_out_modules_that_are_sold_separately(): void
    {
        $command = new \App\Console\Commands\BuildRelease;
        $list = (fn (string $path) => $this->filesUnder($path))->call($command, 'addons');

        $this->assertContains('addons/Meydan/Example/addon.json', $list);
        foreach ($list as $file) {
            $this->assertTrue($file === 'addons/README.md' || str_starts_with($file, 'addons/Meydan/'), 'Not part of the forum package: '.$file);
        }
        // A module installed on the machine that builds the release stays out.
        $marker = base_path('addons/WebDanismani/Example-test-marker/addon.json');
        \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($marker));
        file_put_contents($marker, '{}');
        try {
            $again = (fn (string $path) => $this->filesUnder($path))->call($command, 'addons');
            $this->assertNotContains('addons/WebDanismani/Example-test-marker/addon.json', $again);
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory(dirname($marker));
        }
    }

    public function test_the_admin_panel_lists_what_is_left_to_set_up_and_finds_settings_and_members(): void
    {
        $admin = User::factory()->inGroup('admin')->create(['username' => 'yonetici']);
        User::factory()->create(['username' => 'seocu', 'email' => 'seo@ornek.com']);
        $checklist = app(Checklist::class);
        $state = fn (string $title) => collect($checklist->items())->firstWhere('title', $title)['state'] ?? null;

        // A fresh board: mail, card payments and captcha are still to do; text messages are optional.
        $this->assertSame(['todo', 'todo', 'todo', 'optional'], [$state('Outgoing e-mail'), $state('Card payments (PayTR)'), $state('Captcha on the registration form'), $state('Text messages (phone verification)')]);
        $this->actingAs($this->member)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()
            ->assertSee('Kurulum kontrol listesi')->assertSee('Giden e-posta')->assertSee('Kartla ödeme (PayTR)')
            ->assertSee(route('admin.settings.edit').'#s-', false);

        // Settled items leave the list; PayTR in test mode is pointed out.
        app(Settings::class)->set([
            'mail.driver' => 'smtp', 'ads.paytr_merchant_id' => '123', 'ads.paytr_merchant_key' => 'key', 'ads.paytr_merchant_salt' => 'salt',
            'captcha.provider' => 'turnstile', 'captcha.site_key' => 'site', 'captcha.secret_key' => 'secret',
        ]);
        $this->assertSame(['done', 'done', 'done', 'todo'], [$state('Outgoing e-mail'), $state('Card payments (PayTR)'), $state('Captcha on the registration form'), $state('PayTR is in test mode')]);
        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('Posta sunucusu olmadan')->assertSee('PayTR deneme modunda');
        app(Settings::class)->set(['ads.paytr_test_mode' => false, 'board.name' => 'Webmaster Forumu', 'board.description' => 'Webmasterların buluşma noktası.']);
        config(['app.debug' => false]);
        $this->assertSame(0, $checklist->counts()['todo']);
        // Only optional items are left: the list stays, closed.
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('Kurulum kontrol listesi')->assertDontSee('yapılacak');

        // The settings page has a place to jump to for every group, and the search finds settings by their Turkish label.
        $this->get(route('admin.settings.edit'))->assertOk()->assertSee('id="s-', false);
        $this->get(route('admin.dashboard'))->assertSee('data-admin-search="/admin/search"', false);
        $found = $this->getJson(route('admin.search', ['q' => 'captcha']))->assertOk()->json();
        $this->assertNotEmpty($found['settings']);
        $this->assertStringContainsString('#captcha__', $found['settings'][0]['url']);
        $byMember = $this->getJson(route('admin.search', ['q' => 'seo']))->json('members');
        $this->assertSame(['seocu'], array_column($byMember, 'title'));
        $this->assertSame(['seocu'], array_column($this->getJson(route('admin.search', ['q' => 'seo@ornek']))->json('members'), 'title'));
        $this->assertSame(['settings' => [], 'members' => []], $this->getJson(route('admin.search', ['q' => 'x']))->json());
        $this->actingAs($this->member)->getJson(route('admin.search', ['q' => 'captcha']))->assertForbidden();
    }

    public function test_the_staff_keeps_notes_about_members_and_reports_offer_reasons(): void
    {
        app(Settings::class)->set(['posting.flood_seconds' => 0]);
        $moderator = User::factory()->inGroup('moderator')->create(['username' => 'modcu']);
        $other = User::factory()->inGroup('moderator')->create(['username' => 'digermod']);
        $admin = User::factory()->inGroup('admin')->create();
        DB::table('username_changes')->insert(['user_id' => $this->member->id, 'old_name' => 'eski_adim', 'new_name' => 'hostingci', 'actor_id' => $this->member->id, 'created_at' => now()]);

        // Members and guests see nothing of it and cannot write.
        $this->get(route('members.show', $this->member))->assertOk()->assertDontSee('Ekip notları')->assertDontSee('eski_adim');
        $this->actingAs($this->member)->get(route('members.show', $this->member))->assertOk()->assertDontSee('Ekip notları');
        $this->post(route('member-notes.store', $this->member), ['body' => 'Kendime not'])->assertForbidden();

        // A moderator writes a note; the other moderators read it, with the earlier names of the account.
        $this->actingAs($moderator)->get(route('members.show', $this->member))->assertOk()->assertSee('Ekip notları')->assertSee('eski_adim')->assertSee('Bu üye hakkında henüz not yok.');
        $this->post(route('member-notes.store', $this->member), ['body' => 'İki kez aynı ilanı açtı, uyarıldı.'])->assertSessionHas('success');
        $note = DB::table('user_notes')->first();
        $this->actingAs($other)->get(route('members.show', $this->member))->assertOk()->assertSee('İki kez aynı ilanı açtı, uyarıldı.')->assertSee('modcu');

        // Deleting: the author or an administrator.
        $this->delete(route('member-notes.destroy', $note->id))->assertForbidden();
        $this->actingAs($this->member)->delete(route('member-notes.destroy', $note->id))->assertForbidden();
        $this->actingAs($admin)->delete(route('member-notes.destroy', $note->id))->assertSessionHas('success');
        $this->assertSame(0, DB::table('user_notes')->count());

        // ---- Reporting a post: a reason from the list, details optional ----------------------------------
        $thread = app(Posting::class)->createThread($this->forum, $moderator, 'Şikâyet edilecek konu', 'Metin');
        $post = $thread->first_post_id;
        $this->actingAs($this->member)->get(route('threads.show', $thread))->assertOk()->assertSee('name="reason_type"', false)->assertSee('Spam veya reklam')->assertSee('Bir sebep seçin');

        $this->post(route('posts.report', $post), [])->assertSessionHasErrors('reason');
        $this->post(route('posts.report', $post), ['reason_type' => 'Uydurma sebep'])->assertSessionHasErrors('reason_type');
        $this->post(route('posts.report', $post), ['reason_type' => 'Spam veya reklam'])->assertSessionHas('success');
        $this->assertSame('Spam veya reklam', DB::table('reports')->value('reason'));

        $this->actingAs($admin)->post(route('posts.report', $post), ['reason_type' => 'Dolandırıcılık şüphesi', 'reason' => 'Ödeme aldı, ürünü göndermedi.'])->assertSessionHas('success');
        $this->assertSame('Dolandırıcılık şüphesi — Ödeme aldı, ürünü göndermedi.', DB::table('reports')->orderByDesc('id')->value('reason'));

        // Free text alone still works, and a board without a list shows the plain text box.
        $free = User::factory()->create();
        $this->actingAs($free)->post(route('posts.report', $post), ['reason' => 'Başka bir sorun var.'])->assertSessionHas('success');
        app(Settings::class)->set(['reports.reasons' => '']);
        $this->get(route('threads.show', $thread))->assertOk()->assertDontSee('name="reason_type"', false)->assertSee('name="reason"', false);
    }
}
