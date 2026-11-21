<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Browsers a member signed in from (active sessions, "new device" alerts, accounts that share a browser),
 * a colour and an icon for each step of the title ladder, and a starting text plus an obligatory prefix per forum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->index(); // lives in a cookie of the browser; the same for every account used in it
            $table->string('user_agent', 300)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->unique(['user_id', 'token']);
        });

        Schema::table('user_titles', function (Blueprint $table) {
            $table->string('color', 20)->nullable()->after('title');
            $table->string('icon', 20)->nullable()->after('color');
        });

        Schema::table('nodes', function (Blueprint $table) {
            $table->text('thread_template')->nullable();
            $table->boolean('require_prefix')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('nodes', fn (Blueprint $table) => $table->dropColumn(['thread_template', 'require_prefix']));
        Schema::table('user_titles', fn (Blueprint $table) => $table->dropColumn(['color', 'icon']));
        Schema::dropIfExists('user_devices');
    }
};
