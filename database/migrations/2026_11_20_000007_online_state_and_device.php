<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signing out takes a member off the online list at once (signed_out_at), and the kind of
 * device of the last visit is remembered so "online" can say phone or computer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('signed_out_at')->nullable();
            $table->string('last_device', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['signed_out_at', 'last_device']));
    }
};
