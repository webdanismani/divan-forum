<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per licensed product on this forum: the forum itself, a sold module, a sold theme.
        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->string('product', 64)->unique();
            $table->text('license_key');                      // encrypted
            $table->text('token')->nullable();                // last signed answer of the licence server
            $table->text('chain')->nullable();                // the chain of trust that came with it
            $table->string('status', 20)->default('pending'); // active | refused
            $table->string('message')->nullable();            // why the server refused it
            $table->timestamp('valid_until')->nullable();     // the token is good until then without the server
            $table->timestamp('license_ends_at')->nullable(); // end of the licence itself (null: unlimited)
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};
