<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A picture the administrator uploads for a category or forum, shown instead of the standard icon. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->string('icon_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', fn (Blueprint $table) => $table->dropColumn('icon_path'));
    }
};
