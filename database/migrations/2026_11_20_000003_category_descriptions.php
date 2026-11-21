<?php

use App\Forum\Installer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The two starter categories get the one-line description that the category bar now shows. Edited texts are left alone. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Installer::CATEGORY_TEXT as $title => $description) {
            DB::table('nodes')->where('type', 'category')->where('title', $title)
                ->where(fn ($q) => $q->whereNull('description')->orWhere('description', ''))
                ->update(['description' => $description]);
        }
    }

    public function down(): void {}
};
