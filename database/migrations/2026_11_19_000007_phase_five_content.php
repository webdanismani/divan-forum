<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Custom pages, notices, navigation links, contact messages, announcement e-mails, full-text search. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('email_announcements')->default(true);
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('title', 150);
            $table->longText('body');
            $table->longText('body_html')->nullable();
            $table->boolean('is_published')->default(false);
            $table->boolean('show_in_footer')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150)->nullable();
            $table->text('body');
            $table->text('body_html')->nullable();
            $table->string('style', 10)->default('info'); // info|success|warning
            $table->string('audience', 8)->default('all'); // all|guests|members
            $table->boolean('is_dismissible')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('nav_links', function (Blueprint $table) {
            $table->id();
            $table->string('title', 60);
            $table->string('url');
            $table->string('position', 8)->default('header'); // header|footer
            $table->boolean('new_tab')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 80);
            $table->string('email');
            $table->string('subject', 150);
            $table->text('body');
            $table->string('ip', 45)->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->nullable();
        });

        // Word search instead of scanning every post; other databases keep the LIKE search.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE threads ADD FULLTEXT threads_title_fulltext (title)');
            DB::statement('ALTER TABLE posts ADD FULLTEXT posts_body_fulltext (body)');
        }

        $now = now();
        $rules = "[b]1.[/b] Diğer üyelere saygılı olun; hakaret, tehdit ve nefret söylemi yasaktır.\n"
            ."[b]2.[/b] Konunuzu doğru bölüme açın ve açıklayıcı bir başlık kullanın.\n"
            ."[b]3.[/b] İzinsiz reklam, spam ve aynı içeriği tekrar tekrar paylaşmak yasaktır.\n"
            ."[b]4.[/b] Telif hakkı ihlali içeren veya yasa dışı içerik paylaşmayın.\n"
            ."[b]5.[/b] Kişisel verileri (telefon, adres, kimlik bilgisi) açık alanlarda paylaşmayın.\n"
            ."[b]6.[/b] Moderatör kararlarına itirazlarınızı özel mesajla iletin.\n\n"
            .'Kurallara uymayan içerikler kaldırılabilir, tekrarında üyelik kısıtlanabilir.';

        DB::table('pages')->insert([
            ['slug' => 'kurallar', 'title' => 'Forum Kuralları', 'body' => $rules, 'body_html' => null, 'is_published' => false, 'show_in_footer' => true, 'display_order' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'gizlilik', 'title' => 'Gizlilik ve KVKK', 'body' => 'Bu sayfaya kişisel verilerin işlenmesine ilişkin aydınlatma metninizi yazın.', 'body_html' => null, 'is_published' => false, 'show_in_footer' => true, 'display_order' => 20, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE threads DROP INDEX threads_title_fulltext');
            DB::statement('ALTER TABLE posts DROP INDEX posts_body_fulltext');
        }

        foreach (['contact_messages', 'nav_links', 'notices', 'pages'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('email_announcements'));
    }
};
