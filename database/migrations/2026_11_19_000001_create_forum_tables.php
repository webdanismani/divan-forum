<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Node tree: categories, forums and link-forums (XenForo style).
        Schema::create('nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('nodes')->nullOnDelete();
            $table->string('type', 20)->default('forum'); // category|forum|link
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('link_url')->nullable();
            $table->integer('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('require_approval')->default(false);
            $table->unsignedInteger('thread_count')->default(0);
            $table->unsignedInteger('post_count')->default(0);
            $table->unsignedBigInteger('last_thread_id')->nullable();
            $table->unsignedBigInteger('last_post_user_id')->nullable();
            $table->timestamp('last_post_at')->nullable();
            $table->timestamps();
            $table->index(['parent_id', 'display_order']);
        });

        Schema::create('node_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
            $table->json('permissions')->nullable();
            $table->unique(['node_id', 'user_group_id']);
        });

        Schema::create('thread_prefixes', function (Blueprint $table) {
            $table->id();
            $table->string('title', 40);
            $table->string('color', 20)->default('blue');
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('prefix_id')->nullable()->constrained('thread_prefixes')->nullOnDelete();
            $table->string('title');
            $table->string('state', 12)->default('visible'); // visible|moderated|deleted
            $table->boolean('is_sticky')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->unsignedInteger('reply_count')->default(0);
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedBigInteger('first_post_id')->nullable();
            $table->unsignedBigInteger('last_post_id')->nullable();
            $table->unsignedBigInteger('last_post_user_id')->nullable();
            $table->timestamp('last_post_at')->nullable();
            $table->timestamps();
            $table->index(['node_id', 'state', 'is_sticky', 'last_post_at']);
            $table->index(['state', 'last_post_at']);
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->longText('body');
            $table->longText('body_html')->nullable();
            $table->string('state', 12)->default('visible');
            $table->integer('reaction_score')->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->unsignedInteger('edit_count')->default(0);
            $table->timestamp('edited_at')->nullable();
            $table->unsignedBigInteger('edited_by')->nullable();
            $table->timestamps();
            $table->index(['thread_id', 'state', 'id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['state', 'created_at']);
        });

        Schema::create('reactions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40);
            $table->string('emoji', 16);
            $table->smallInteger('score')->default(1);
            $table->integer('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('post_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reaction_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['post_id', 'user_id']);
        });

        Schema::create('thread_watches', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['user_id', 'thread_id']);
        });

        Schema::create('thread_reads', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->primary(['user_id', 'thread_id']);
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('message_count')->default(0);
            $table->unsignedBigInteger('last_message_user_id')->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_read_at')->nullable();
            $table->primary(['conversation_id', 'user_id']);
        });

        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->longText('body');
            $table->longText('body_html')->nullable();
            $table->timestamps();
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('reason');
            $table->string('status', 12)->default('open'); // open|resolved|rejected
            $table->unsignedBigInteger('handled_by')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('moderator_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 60);
            $table->string('content_type', 30)->nullable();
            $table->unsignedBigInteger('content_id')->nullable();
            $table->string('summary')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'notifications', 'settings', 'moderator_logs', 'reports', 'conversation_messages',
            'conversation_participants', 'conversations', 'thread_reads', 'thread_watches',
            'post_reactions', 'reactions', 'posts', 'threads', 'thread_prefixes',
            'node_permissions', 'nodes',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
