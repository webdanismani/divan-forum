<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A design for each user group's banner: one of the ready-made styles, an icon and a second colour. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_groups', function (Blueprint $table) {
            $table->string('banner_style', 20)->nullable()->after('badge');
            $table->string('banner_icon', 20)->nullable()->after('banner_style');
            $table->string('banner_color2', 20)->nullable()->after('banner_icon');
        });

        DB::table('user_groups')->where('system_key', 'admin')->update(['banner_style' => 'plate', 'banner_icon' => 'crown']);
        DB::table('user_groups')->where('system_key', 'moderator')->update(['banner_style' => 'plate', 'banner_icon' => 'shield']);
    }

    public function down(): void
    {
        Schema::table('user_groups', fn (Blueprint $table) => $table->dropColumn(['banner_style', 'banner_icon', 'banner_color2']));
    }
};
