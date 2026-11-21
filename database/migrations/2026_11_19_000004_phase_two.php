<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Polls, tags, question threads, bookmarks, edit history, forum watching and e-mail preferences. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->boolean('allow_solutions')->default(false); // question-and-answer forum
        });

        Schema::table('threads', function (Blueprint $table) {
            $table->unsignedBigInteger('solution_post_id')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('solution_count')->default(0);
            $table->boolean('email_on_reply')->default(true);
            $table->boolean('email_on_message')->default(true);
            $table->string('email_digest', 8)->default('none'); // none|daily|weekly
            $table->timestamp('digest_sent_at')->nullable();
        });

        Schema::create('polls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('question');
            $table->unsignedTinyInteger('max_choices')->default(1);
            $table->boolean('can_change')->default(true);
            $table->unsignedInteger('voter_count')->default(0);
            $table->timestamp('closes_at')->nullable();
            $table->timestamps();
        });

        Schema::create('poll_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained()->cascadeOnDelete();
            $table->string('text', 150);
            $table->unsignedInteger('vote_count')->default(0);
            $table->integer('display_order')->default(0);
        });

        Schema::create('poll_votes', function (Blueprint $table) {
            $table->foreignId('poll_id')->constrained()->cascadeOnDelete();
            $table->foreignId('poll_option_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['poll_option_id', 'user_id']);
            $table->index(['poll_id', 'user_id']);
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40);
            $table->string('slug', 60)->unique();
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamps();
        });

        Schema::create('tag_thread', function (Blueprint $table) {
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->primary(['tag_id', 'thread_id']);
            $table->index('thread_id');
        });

        Schema::create('bookmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('note', 200)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['user_id', 'post_id']);
        });

        Schema::create('post_edits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // who made the edit
            $table->longText('old_body');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('node_watches', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['user_id', 'node_id']);
        });

        foreach (['registered', 'moderator', 'admin'] as $key) {
            $group = DB::table('user_groups')->where('system_key', $key)->first();
            if ($group) {
                $current = json_decode((string) $group->permissions, true) ?: [];
                DB::table('user_groups')->where('id', $group->id)->update([
                    'permissions' => json_encode($current + ['poll.create' => 'allow', 'poll.vote' => 'allow']),
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (['node_watches', 'post_edits', 'bookmarks', 'tag_thread', 'tags', 'poll_votes', 'poll_options', 'polls'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['solution_count', 'email_on_reply', 'email_on_message', 'email_digest', 'digest_sent_at']));
        Schema::table('threads', fn (Blueprint $table) => $table->dropColumn('solution_post_id'));
        Schema::table('nodes', fn (Blueprint $table) => $table->dropColumn('allow_solutions'));
    }
};
