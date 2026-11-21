<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Interface texts changed in the admin panel. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phrases', function (Blueprint $table) {
            $table->id();
            $table->string('locale', 5);
            $table->string('key_hash', 40); // sha1 of the key: keys are whole sentences, too long for an index
            $table->text('key');
            $table->text('value');
            $table->timestamps();
            $table->unique(['locale', 'key_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phrases');
    }
};
