<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attachments, warnings, payments + wallet, paid thread promotion and paid
 * memberships.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('warning_points')->default(0);
            $table->decimal('balance', 12, 2)->default(0);
            $table->string('registration_ip', 45)->nullable();
            // Remembered from the last order, the payment provider asks for them.
            $table->string('billing_name', 60)->nullable();
            $table->string('billing_phone', 20)->nullable();
            $table->string('billing_address', 400)->nullable();
        });

        Schema::table('threads', function (Blueprint $table) {
            $table->timestamp('promoted_until')->nullable(); // paid sticky
            $table->timestamp('highlight_until')->nullable(); // paid bold/coloured title
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('post_id')->nullable()->constrained()->nullOnDelete();
            $table->string('upload_key', 40)->nullable()->index(); // groups uploads made while writing
            $table->string('disk_path');
            $table->string('thumb_path')->nullable();
            $table->string('filename');
            $table->string('extension', 12);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->boolean('is_image')->default(false);
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamps();
        });

        Schema::create('warning_types', function (Blueprint $table) {
            $table->id();
            $table->string('title', 100);
            $table->unsignedSmallInteger('points')->default(1);
            $table->unsignedSmallInteger('expiry_days')->default(30); // 0 = never
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('warnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('post_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 100);
            $table->unsignedSmallInteger('points');
            $table->string('note', 500)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'expires_at']);
        });

        // When a member's active points reach a threshold they are banned for a while.
        Schema::create('warning_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('points');
            $table->unsignedSmallInteger('ban_days')->default(0); // 0 = permanent
            $table->timestamps();
        });

        // One row per thing somebody pays for (ad, wallet top-up, membership).
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 20); // ad|wallet|upgrade
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('title');
            $table->decimal('amount', 10, 2);
            $table->string('status', 12)->default('pending'); // pending|review|paid|failed|cancelled
            $table->string('method', 12)->nullable(); // card|transfer
            $table->string('reference', 64)->nullable()->index();
            $table->decimal('paid_amount', 10, 2)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['purpose', 'subject_id']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2); // negative = spent
            $table->decimal('balance_after', 12, 2);
            $table->string('type', 20); // topup|ad|promotion|upgrade|adjustment
            $table->string('description');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['user_id', 'id']);
        });

        Schema::create('promotion_options', function (Blueprint $table) {
            $table->id();
            $table->string('type', 12); // sticky|highlight|bump
            $table->unsignedSmallInteger('days')->default(0); // 0 for bump
            $table->decimal('price', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('thread_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 12);
            $table->unsignedSmallInteger('days')->default(0);
            $table->decimal('price', 10, 2);
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('user_upgrades', function (Blueprint $table) {
            $table->id();
            $table->string('title', 100);
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->unsignedSmallInteger('days')->default(30); // 0 = permanent
            $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('user_upgrade_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_upgrade_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('status', 12)->default('active'); // active|expired
            $table->timestamps();
            $table->index(['status', 'ends_at']);
        });

        $now = now();
        foreach ([['Kurallara aykırı içerik', 1, 30], ['Spam / izinsiz reklam', 2, 60], ['Hakaret ve saygısızlık', 3, 90]] as $i => [$title, $points, $days]) {
            DB::table('warning_types')->insert(['title' => $title, 'points' => $points, 'expiry_days' => $days, 'display_order' => ($i + 1) * 10, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ([[5, 7], [10, 0]] as [$points, $days]) {
            DB::table('warning_actions')->insert(['points' => $points, 'ban_days' => $days, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ([['sticky', 7, 150], ['sticky', 30, 450], ['highlight', 7, 60], ['highlight', 30, 180], ['bump', 0, 20]] as [$type, $days, $price]) {
            DB::table('promotion_options')->insert(['type' => $type, 'days' => $days, 'price' => $price, 'created_at' => $now, 'updated_at' => $now]);
        }

        // Boards installed before these permissions existed: give them to the default groups.
        $grants = [
            'guest' => ['attachment.view'],
            'registered' => ['attachment.view', 'attachment.upload'],
            'moderator' => ['attachment.view', 'attachment.upload', 'mod.warn', 'mod.merge'],
            'admin' => ['attachment.view', 'attachment.upload', 'mod.warn', 'mod.merge'],
        ];
        foreach ($grants as $key => $permissions) {
            $group = DB::table('user_groups')->where('system_key', $key)->first();
            if ($group) {
                $current = json_decode((string) $group->permissions, true) ?: [];
                DB::table('user_groups')->where('id', $group->id)->update([
                    'permissions' => json_encode($current + array_fill_keys($permissions, 'allow')),
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'user_upgrade_purchases', 'user_upgrades', 'thread_promotions', 'promotion_options',
            'wallet_transactions', 'payments', 'warning_actions', 'warnings', 'warning_types', 'attachments',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('threads', fn (Blueprint $table) => $table->dropColumn(['promoted_until', 'highlight_until']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['warning_points', 'balance', 'registration_ip', 'billing_name', 'billing_phone', 'billing_address']));
    }
};
