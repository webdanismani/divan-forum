<?php

namespace App\Forum;

use App\Models\Node;
use App\Models\Reaction;
use App\Models\ThreadPrefix;
use App\Models\User;
use App\Models\UserGroup;

/** Creates the data every board needs: system groups, reactions, a starter structure. */
class Installer
{
    public function installDefaults(): void
    {
        $this->groups();
        $this->reactions();
        $this->prefixes();
        $this->structure();
    }

    public function createAdmin(string $username, string $email, string $password): User
    {
        return User::create([
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'email_verified_at' => now(),
            'user_group_id' => UserGroup::system('admin')->id,
        ]);
    }

    /** One line shown in the category bar on the home page. */
    public const CATEGORY_TEXT = ['Genel' => 'Duyurular, tanışma ve serbest sohbet.', 'Destek' => 'Sorularınız, önerileriniz ve geri bildirimleriniz.'];

    protected function groups(): void
    {
        $member = array_fill_keys([
            'node.view', 'profile.view', 'search.use', 'conversation.start', 'profile.post', 'api.use', 'shoutbox.post', 'friends.use', 'privacy.profile', 'signature.use', 'replies.saved', 'account.delete',
            'thread.create', 'post.create', 'post.editOwn', 'post.deleteOwn', 'post.react', 'post.report',
            'attachment.view', 'attachment.upload', 'poll.create', 'poll.vote',
        ], 'allow');

        $moderator = $member + array_fill_keys([
            'mod.editAny', 'mod.deleteAny', 'mod.viewDeleted', 'mod.approve',
            'mod.lock', 'mod.stick', 'mod.move', 'mod.merge', 'mod.manageReports', 'mod.warn', 'mod.spamClean',
            'privacy.contact', 'privacy.online', 'signature.links', 'signature.images', 'signature.long', 'account.rename', 'profile.links',
        ], 'allow');

        $all = array_fill_keys(collect(config('forum.permissions'))->collapse()->keys()->all(), 'allow');

        $groups = [
            'guest' => ['name' => 'Misafir', 'priority' => 0, 'permissions' => ['node.view' => 'allow', 'profile.view' => 'allow', 'search.use' => 'allow', 'attachment.view' => 'allow']],
            'registered' => ['name' => 'Üye', 'priority' => 10, 'permissions' => $member],
            'moderator' => ['name' => 'Moderatör', 'priority' => 50, 'color' => '#0f9d6b', 'badge' => 'Moderatör', 'banner_style' => 'plate', 'banner_icon' => 'shield', 'is_staff' => true, 'conversation_quota' => 500, 'permissions' => $moderator],
            'admin' => ['name' => 'Yönetici', 'priority' => 100, 'color' => '#d6455d', 'badge' => 'Yönetici', 'banner_style' => 'plate', 'banner_icon' => 'crown', 'is_staff' => true, 'conversation_quota' => 0, 'permissions' => $all],
        ];

        foreach ($groups as $key => $attributes) {
            UserGroup::firstOrCreate(['system_key' => $key], $attributes);
        }
    }

    protected function reactions(): void
    {
        if (Reaction::exists()) {
            return;
        }

        foreach ([['Beğen', '👍', 1], ['Bayıldım', '❤️', 1], ['Komik', '😄', 1], ['Bilgilendirici', '💡', 1], ['Üzgün', '😢', 0], ['Katılmıyorum', '👎', -1]] as $i => [$name, $emoji, $score]) {
            Reaction::create(['name' => $name, 'emoji' => $emoji, 'score' => $score, 'display_order' => ($i + 1) * 10]);
        }
    }

    protected function prefixes(): void
    {
        if (ThreadPrefix::exists()) {
            return;
        }

        foreach ([['Soru', 'blue'], ['Çözüldü', 'green'], ['Duyuru', 'red'], ['Rehber', 'purple']] as $i => [$title, $color]) {
            ThreadPrefix::create(['title' => $title, 'color' => $color, 'display_order' => ($i + 1) * 10]);
        }
    }

    protected function structure(): void
    {
        if (Node::exists()) {
            return;
        }

        $structure = [
            'Genel' => [
                ['Duyurular', 'Forum yönetiminden haberler ve duyurular.'],
                ['Tanışma', 'Kendinizi tanıtın, topluluğa merhaba deyin.'],
                ['Genel Sohbet', 'Aklınıza gelen her konuda serbest sohbet.'],
            ],
            'Destek' => [
                ['Sorular ve Yardım', 'Takıldığınız konularda topluluktan yardım alın.'],
                ['Öneri ve Geri Bildirim', 'Forum hakkındaki fikirlerinizi paylaşın.'],
            ],
        ];

        $order = 0;
        foreach ($structure as $categoryTitle => $forums) {
            $category = Node::create(['type' => 'category', 'title' => $categoryTitle, 'description' => self::CATEGORY_TEXT[$categoryTitle] ?? null, 'display_order' => $order += 10]);
            foreach ($forums as $i => [$title, $description]) {
                Node::create([
                    'type' => 'forum', 'parent_id' => $category->id, 'title' => $title,
                    'description' => $description, 'display_order' => ($i + 1) * 10,
                ]);
            }
        }

        // Only staff may start threads in the announcements forum.
        $announcements = Node::where('title', 'Duyurular')->first();
        $announcements->permissionEntries()->create([
            'user_group_id' => UserGroup::system('registered')->id,
            'permissions' => ['thread.create' => 'never'],
        ]);
    }
}
