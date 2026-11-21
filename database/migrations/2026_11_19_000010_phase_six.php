<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Marketplace and trader feedback, phone verification, ad extras, AI helpers, shoutbox. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->boolean('is_marketplace')->default(false); // threads are listings with a type, a price and a state
        });

        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 8)->default('sell'); // sell|buy|service
            $table->decimal('price', 12, 2)->nullable(); // null = "ask"
            $table->string('status', 8)->default('open'); // open|sold|closed
            $table->timestamp('featured_until')->nullable(); // paid showcase
            $table->timestamps();
            $table->index(['status', 'featured_until']);
        });

        Schema::create('trade_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $table->tinyInteger('rating'); // 1 positive, 0 neutral, -1 negative
            $table->string('role', 6); // what the author was in the deal: buyer|seller
            $table->string('comment', 300);
            $table->timestamps();
            $table->unique(['thread_id', 'from_user_id', 'to_user_id']);
            $table->index(['to_user_id', 'id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('trade_positive')->default(0);
            $table->unsignedInteger('trade_neutral')->default(0);
            $table->unsignedInteger('trade_negative')->default(0);
            $table->string('phone', 20)->nullable();
            $table->timestamp('phone_verified_at')->nullable();
        });

        Schema::create('phone_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('phone', 20);
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('sent_count')->default(1); // codes sent today
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::table('ad_zones', function (Blueprint $table) {
            $table->string('kind', 8)->default('banner'); // banner|text
        });

        Schema::table('ads', function (Blueprint $table) {
            $table->string('coupon_code', 30)->nullable();
            $table->decimal('discount', 10, 2)->default(0);
            $table->timestamp('reminded_at')->nullable(); // "your ad ends soon" was sent
            $table->unsignedBigInteger('renewal_of')->nullable(); // the ad this order continues
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->unsignedTinyInteger('percent')->nullable(); // either a percentage ...
            $table->decimal('amount', 10, 2)->nullable(); // ... or a fixed amount
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('threads', function (Blueprint $table) {
            $table->timestamp('sponsored_until')->nullable(); // paid place in the "sponsored threads" box
        });

        Schema::create('shouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('body', 300);
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::create('thread_summaries', function (Blueprint $table) {
            $table->foreignId('thread_id')->primary()->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->unsignedInteger('post_count'); // posts the summary is based on
            $table->timestamps();
        });

        // A place for sponsored text links in the footer. The prices are examples: change them in the admin panel.
        DB::table('ad_zones')->insert([
            'position' => 'links',
            'kind' => 'text',
            'title' => 'Sponsor bağlantılar',
            'description' => 'Tüm sayfaların altında metin bağlantısı olarak görünür.',
            'width' => 0,
            'height' => 0,
            'slots' => 6,
            'prices' => json_encode([['days' => 30, 'price' => 150], ['days' => 90, 'price' => 400]]),
            'is_active' => true,
            'display_order' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['registered', 'moderator', 'admin'] as $key) {
            $group = DB::table('user_groups')->where('system_key', $key)->first();
            if ($group) {
                $current = json_decode((string) $group->permissions, true) ?: [];
                DB::table('user_groups')->where('id', $group->id)->update(['permissions' => json_encode($current + ['shoutbox.post' => 'allow'])]);
            }
        }
    }

    public function down(): void
    {
        foreach (['thread_summaries', 'shouts', 'coupons', 'phone_verifications', 'trade_feedback', 'listings'] as $table) {
            Schema::dropIfExists($table);
        }

        DB::table('ad_zones')->where('position', 'links')->delete();
        Schema::table('threads', fn (Blueprint $table) => $table->dropColumn('sponsored_until'));
        Schema::table('ads', fn (Blueprint $table) => $table->dropColumn(['coupon_code', 'discount', 'reminded_at', 'renewal_of']));
        Schema::table('ad_zones', fn (Blueprint $table) => $table->dropColumn('kind'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['trade_positive', 'trade_neutral', 'trade_negative', 'phone', 'phone_verified_at']));
        Schema::table('nodes', fn (Blueprint $table) => $table->dropColumn('is_marketplace'));
    }
};
