<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Site addresses in the profile text are for the staff unless a group is given the permission. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['moderator', 'admin'] as $key) {
            $group = DB::table('user_groups')->where('system_key', $key)->first();
            if ($group) {
                $current = json_decode((string) $group->permissions, true) ?: [];
                DB::table('user_groups')->where('id', $group->id)->update(['permissions' => json_encode($current + ['profile.links' => 'allow'])]);
            }
        }
    }

    public function down(): void {}
};
