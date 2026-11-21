<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Account comfort: which alerts a member wants and how (site, e-mail, browser), username changes
 * with their history, a request to have the account deleted, the welcome step after registering,
 * and notes the staff keeps about a member.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('alert_prefs')->nullable();
            $table->timestamp('deletion_requested_at')->nullable();
            $table->string('deletion_reason', 300)->nullable();
            $table->timestamp('onboarded_at')->nullable();
        });

        Schema::create('username_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('old_name', 60);
            $table->string('new_name', 60);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['user_id', 'id']);
        });

        Schema::create('user_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('body', 1000);
            $table->timestamps();
            $table->index(['user_id', 'id']);
        });

        // Members who are here already do not get the welcome step.
        DB::table('users')->update(['onboarded_at' => now()]);

        $grants = [
            'registered' => ['account.delete'],
            'moderator' => ['account.delete', 'account.rename'],
            'admin' => ['account.delete', 'account.rename'],
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
        Schema::dropIfExists('user_notes');
        Schema::dropIfExists('username_changes');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['alert_prefs', 'deletion_requested_at', 'deletion_reason', 'onboarded_at']));
    }
};
