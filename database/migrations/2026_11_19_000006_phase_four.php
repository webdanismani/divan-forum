<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Per-forum moderators; the spam cleaner permission. */
return new class extends Migration
{
    public function up(): void
    {
        // A member listed here moderates the forum and everything below it.
        Schema::create('node_moderators', function (Blueprint $table) {
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['node_id', 'user_id']);
            $table->index('user_id');
        });

        foreach (['moderator', 'admin'] as $key) {
            $group = DB::table('user_groups')->where('system_key', $key)->first();
            if ($group) {
                $current = json_decode((string) $group->permissions, true) ?: [];
                DB::table('user_groups')->where('id', $group->id)->update(['permissions' => json_encode($current + ['mod.spamClean' => 'allow'])]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('node_moderators');
    }
};
