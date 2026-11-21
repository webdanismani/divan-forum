<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Friends, per-member privacy choices, and the group permissions that decide who gets which of them (and what a signature may hold). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friendships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();   // who asked
            $table->foreignId('friend_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 10)->default('pending'); // pending|accepted
            $table->timestamps();
            $table->unique(['user_id', 'friend_id']);
            $table->index(['friend_id', 'status']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->text('privacy')->nullable(); // {"profile": "everyone|members|friends|nobody", "wall": ..., "messages": ..., "online": true, "requests": true}
        });

        // Free members: friends, choosing who sees the profile, a plain signature. The rest is for groups the administrator picks.
        $grants = [
            'registered' => ['friends.use', 'privacy.profile', 'signature.use'],
            'moderator' => ['friends.use', 'privacy.profile', 'privacy.contact', 'privacy.online', 'signature.use', 'signature.links', 'signature.images', 'signature.long'],
            'admin' => ['friends.use', 'privacy.profile', 'privacy.contact', 'privacy.online', 'signature.use', 'signature.links', 'signature.images', 'signature.long'],
        ];
        foreach ($grants as $key => $permissions) {
            $group = DB::table('user_groups')->where('system_key', $key)->first();
            if ($group) {
                $current = json_decode((string) $group->permissions, true) ?: [];
                DB::table('user_groups')->where('id', $group->id)->update(['permissions' => json_encode($current + array_fill_keys($permissions, 'allow'))]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('friendships');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('privacy'));
    }
};
