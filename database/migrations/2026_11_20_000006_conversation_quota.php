<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inbox quota: how many conversations a member may keep. The general number is a setting;
 * a user group can have its own (empty = the general number, 0 = no limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_groups', function (Blueprint $table) {
            $table->unsignedInteger('conversation_quota')->nullable();
        });

        // The staff keeps more: moderators five times the usual number, administrators without a limit.
        DB::table('user_groups')->where('system_key', 'moderator')->update(['conversation_quota' => 500]);
        DB::table('user_groups')->where('system_key', 'admin')->update(['conversation_quota' => 0]);
    }

    public function down(): void
    {
        Schema::table('user_groups', fn (Blueprint $table) => $table->dropColumn('conversation_quota'));
    }
};
