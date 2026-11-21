<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A forum can have a named icon and a colour of its own (used by the forum list, most visibly in themes that draw icon tiles). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->string('icon', 24)->nullable();
            $table->string('color', 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', fn (Blueprint $table) => $table->dropColumn(['icon', 'color']));
    }
};
