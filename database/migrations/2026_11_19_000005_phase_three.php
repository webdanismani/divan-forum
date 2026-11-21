<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Two-factor sign-in, social accounts, IP logs and bans, ignoring/following, profile posts, profile fields, titles, badges, promotions. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable(); // encrypted
            $table->text('two_factor_recovery_codes')->nullable(); // encrypted JSON of hashes
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->unsignedBigInteger('two_factor_step')->nullable(); // last accepted time step (no code twice)
            $table->unsignedInteger('follower_count')->default(0);
        });

        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('provider_id', 100);
            $table->timestamps();
            $table->unique(['provider', 'provider_id']);
        });

        Schema::create('ip_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ip', 45)->index();
            $table->string('action', 12); // register|login|post
            $table->timestamp('created_at')->nullable();
            $table->index(['user_id', 'id']);
        });

        Schema::create('bans', function (Blueprint $table) {
            $table->id();
            $table->string('type', 8); // ip|email
            $table->string('value', 190); // "*" matches any run of characters
            $table->string('reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('type');
        });

        Schema::create('user_ignores', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ignored_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['user_id', 'ignored_id']);
        });

        Schema::create('user_follows', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('followed_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['user_id', 'followed_id']);
            $table->index('followed_id');
        });

        Schema::create('profile_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('body', 1000);
            $table->timestamps();
            $table->index(['profile_user_id', 'id']);
        });

        Schema::create('profile_fields', function (Blueprint $table) {
            $table->id();
            $table->string('label', 80);
            $table->string('type', 10)->default('text'); // text|url|select
            $table->json('options')->nullable(); // choices of a select
            $table->boolean('is_required')->default(false);
            $table->boolean('show_on_posts')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('profile_field_values', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_field_id')->constrained()->cascadeOnDelete();
            $table->string('value', 255);
            $table->primary(['user_id', 'profile_field_id']);
        });

        Schema::create('user_titles', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('min_posts')->unique();
            $table->string('title', 60);
            $table->timestamps();
        });

        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->string('title', 60);
            $table->string('description')->nullable();
            $table->string('icon', 16)->default('⭐');
            $table->string('criteria', 12)->default('manual'); // manual|posts|reactions|solutions|days
            $table->unsignedInteger('threshold')->default(0);
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('user_badges', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('badge_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['user_id', 'badge_id']);
        });

        // Members who meet every condition are added to the group automatically.
        Schema::create('group_promotions', function (Blueprint $table) {
            $table->id();
            $table->string('title', 80);
            $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
            $table->unsignedInteger('min_posts')->default(0);
            $table->unsignedInteger('min_days')->default(0);
            $table->unsignedInteger('min_reaction_score')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        foreach ([[0, 'Yeni Üye'], [10, 'Üye'], [100, 'Aktif Üye'], [500, 'Kıdemli Üye'], [2000, 'Usta Üye']] as [$posts, $title]) {
            DB::table('user_titles')->insert(['min_posts' => $posts, 'title' => $title, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ([
            ['İlk Mesaj', 'İlk mesajını yazdı.', '✍️', 'posts', 1],
            ['100 Mesaj', '100 mesaja ulaştı.', '💬', 'posts', 100],
            ['Sevilen Üye', '50 tepki puanı topladı.', '❤️', 'reactions', 50],
            ['Yardımsever', '10 soruya çözüm buldu.', '🛟', 'solutions', 10],
            ['Bir Yıllık Üye', 'Bir yıldır aramızda.', '🎂', 'days', 365],
        ] as $i => [$title, $description, $icon, $criteria, $threshold]) {
            DB::table('badges')->insert(compact('title', 'description', 'icon', 'criteria', 'threshold') + ['display_order' => ($i + 1) * 10, 'created_at' => $now, 'updated_at' => $now]);
        }

        foreach (['registered', 'moderator', 'admin'] as $key) {
            $group = DB::table('user_groups')->where('system_key', $key)->first();
            if ($group) {
                $current = json_decode((string) $group->permissions, true) ?: [];
                DB::table('user_groups')->where('id', $group->id)->update(['permissions' => json_encode($current + ['profile.post' => 'allow'])]);
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'group_promotions', 'user_badges', 'badges', 'user_titles', 'profile_field_values', 'profile_fields',
            'profile_posts', 'user_follows', 'user_ignores', 'bans', 'ip_logs', 'social_accounts',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_step', 'follower_count']));
    }
};
