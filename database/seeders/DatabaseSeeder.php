<?php

namespace Database\Seeders;

use App\Forum\Installer;
use App\Forum\Posting;
use App\Models\Node;
use App\Models\Reaction;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Database\Seeder;

/**
 * Demo data for local development: `php artisan migrate:fresh --seed`.
 * Sign in with admin / password (or moderator / password, ayse / password).
 * Never run this on a production board.
 */
class DatabaseSeeder extends Seeder
{
    public function run(Installer $installer, Posting $posting): void
    {
        $installer->installDefaults();

        $admin = $installer->createAdmin('admin', 'admin@example.com', 'password');
        $registered = UserGroup::system('registered')->id;

        $moderator = User::factory()->create([
            'username' => 'moderator', 'email' => 'moderator@example.com',
            'user_group_id' => UserGroup::system('moderator')->id,
        ]);

        $members = collect(['ayse', 'mehmet', 'zeynep', 'can', 'elif', 'burak'])->map(fn ($name) => User::factory()->create([
            'username' => $name, 'email' => $name.'@example.com', 'user_group_id' => $registered,
            'last_seen_at' => now()->subMinutes(rand(1, 600)),
        ]));

        $forum = fn (string $title) => Node::where('title', $title)->firstOrFail();

        $welcome = $posting->createThread($forum('Duyurular'), $admin, 'Forumumuza hoş geldiniz!',
            "Merhaba,\n\nYeni forumumuz yayında. [b]Kuralları[/b] okumayı ve kendinizi [i]Tanışma[/i] bölümünde tanıtmayı unutmayın.\n\n[list]\n[*]Saygılı olun\n[*]Doğru bölüme yazın\n[*]Arama özelliğini kullanın\n[/list]\n\nİyi forumlar!");
        $welcome->update(['is_sticky' => true, 'prefix_id' => 3]);
        $posting->reply($welcome, $members[0], 'Hayırlı olsun, emeğinize sağlık! 🎉');
        $posting->reply($welcome, $moderator, 'Hoş geldiniz. Sorularınız için [b]Sorular ve Yardım[/b] bölümünü kullanabilirsiniz.');

        $samples = [
            ['Tanışma', 'Herkese merhaba, ben Ayşe', "İzmir'den katılıyorum, yazılım ve fotoğrafçılıkla ilgileniyorum."],
            ['Genel Sohbet', 'Bu hafta sonu ne izlediniz?', 'Film ve dizi önerilerinizi alalım.'],
            ['Genel Sohbet', 'En sevdiğiniz kod editörü hangisi?', "Ben uzun süredir aynı editörü kullanıyorum, siz ne kullanıyorsunuz?\n\n[code]echo 'Merhaba dünya';[/code]"],
            ['Sorular ve Yardım', 'Profil resmimi nasıl değiştiririm?', 'Hesap ayarlarında bulamadım, yardımcı olur musunuz?'],
            ['Sorular ve Yardım', 'Konuya nasıl resim eklenir?', 'BBCode ile resim ekleme nasıl yapılıyor?'],
            ['Öneri ve Geri Bildirim', 'Karanlık tema harika olmuş', 'Gece kullanımında göz yormuyor, teşekkürler.'],
        ];

        $reactions = Reaction::all();

        foreach ($samples as $i => [$forumTitle, $title, $body]) {
            $author = $members[$i % $members->count()];
            $thread = $posting->createThread($forum($forumTitle), $author, $title, $body);

            foreach ($members->shuffle()->take(rand(1, 4)) as $replier) {
                if ($replier->isNot($author)) {
                    $reply = $posting->reply($thread, $replier, "@{$author->username} güzel konu, katkın için teşekkürler.");
                    $posting->react($reply, $author, $reactions->random());
                }
            }
        }

        $solved = $posting->createThread($forum('Sorular ve Yardım'), $members[1], 'Şifremi unuttum, ne yapmalıyım?', 'Giriş yapamıyorum.');
        $solved->update(['prefix_id' => 2]);
        $posting->reply($solved, $moderator, 'Giriş sayfasındaki [b]Şifremi unuttum[/b] bağlantısını kullanabilirsiniz.');
    }
}
