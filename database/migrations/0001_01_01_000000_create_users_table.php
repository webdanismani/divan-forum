<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('system_key')->nullable()->unique(); // guest|registered|moderator|admin
            $table->string('color', 20)->nullable();
            $table->string('badge')->nullable();
            $table->boolean('is_staff')->default(false);
            $table->integer('priority')->default(0);
            $table->json('permissions')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username', 30)->unique();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->foreignId('user_group_id')->constrained('user_groups');
            $table->string('custom_title', 60)->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('location', 80)->nullable();
            $table->string('website')->nullable();
            $table->text('about')->nullable();
            $table->text('signature')->nullable();
            $table->text('signature_html')->nullable();
            $table->unsignedInteger('thread_count')->default(0);
            $table->unsignedInteger('post_count')->default(0);
            $table->integer('reaction_score')->default(0);
            $table->boolean('is_banned')->default(false);
            $table->string('ban_reason')->nullable();
            $table->timestamp('banned_until')->nullable();
            $table->string('theme', 10)->nullable();
            $table->string('locale', 5)->nullable();
            $table->timestamp('forums_read_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->string('last_ip', 45)->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('user_group_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
            $table->primary(['user_id', 'user_group_id']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('user_group_user');
        Schema::dropIfExists('users');
        Schema::dropIfExists('user_groups');
    }
};
