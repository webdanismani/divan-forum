<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** API keys, webhooks, browser push subscriptions and imports from other forum software. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Password from another forum package, checked once and then replaced: {"scheme": "...", "hash": "...", "salt": "..."}
            $table->text('legacy_password')->nullable();
        });

        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('token_hash', 64)->unique(); // sha256 of the key; the key itself is shown once
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('url');
            $table->string('secret', 64);
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('last_called_at')->nullable();
            $table->timestamps();
        });

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint_hash', 64)->unique();
            $table->text('endpoint');
            $table->timestamps();
        });

        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20); // xenforo|vbulletin|mybb|smf
            $table->text('config'); // encrypted connection details, wiped when the run ends
            $table->string('step', 20)->default('users');
            $table->unsignedBigInteger('cursor')->default(0);
            $table->json('stats')->nullable();
            $table->string('status', 12)->default('running'); // running|done|failed
            $table->string('error')->nullable();
            $table->timestamps();
        });

        Schema::create('import_map', function (Blueprint $table) {
            $table->unsignedBigInteger('run_id');
            $table->string('type', 8); // user|forum|thread|post
            $table->unsignedBigInteger('old_id');
            $table->unsignedBigInteger('new_id');
            $table->primary(['run_id', 'type', 'old_id']);
        });

        // Members may create API keys (the API itself is off until it is switched on in the settings).
        foreach (['registered', 'moderator', 'admin'] as $key) {
            $group = DB::table('user_groups')->where('system_key', $key)->first();
            if ($group) {
                $current = json_decode((string) $group->permissions, true) ?: [];
                DB::table('user_groups')->where('id', $group->id)->update(['permissions' => json_encode($current + ['api.use' => 'allow'])]);
            }
        }
    }

    public function down(): void
    {
        foreach (['import_map', 'import_runs', 'push_subscriptions', 'webhooks', 'api_tokens'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('legacy_password'));
    }
};
