<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Texts a member keeps to put into the editor with one click (frequent answers, greetings, terms of sale). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 80);
            $table->text('body');
            $table->timestamps();
            $table->index(['user_id', 'title']);
        });

        foreach (['registered', 'moderator', 'admin'] as $key) {
            $group = DB::table('user_groups')->where('system_key', $key)->first();
            if ($group) {
                $current = json_decode((string) $group->permissions, true) ?: [];
                DB::table('user_groups')->where('id', $group->id)->update(['permissions' => json_encode($current + ['replies.saved' => 'allow'])]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_replies');
    }
};
