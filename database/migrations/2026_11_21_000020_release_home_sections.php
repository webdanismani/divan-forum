<?php

use App\Forum\Home;
use App\Forum\Themes;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Before 0.3.1 a theme left its home page sections (hero, tiles, band…) switched on when it was
     * switched off. Boards in that state get their earlier home page back.
     */
    public function up(): void
    {
        $home = app(Home::class);
        $adopted = $home->adopted();

        if ($adopted !== null && app(Themes::class)->chosenId() !== $adopted) {
            $home->release($adopted);
        }
    }

    public function down(): void {}
};
