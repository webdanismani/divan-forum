<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fixed places in the templates where banners can be shown.
        Schema::create('ad_zones', function (Blueprint $table) {
            $table->id();
            $table->string('position', 30)->unique(); // top|sidebar|thread|bottom
            $table->string('title');
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedTinyInteger('slots')->default(1); // banners shown at the same time
            $table->json('prices'); // [{"days": 30, "price": 750}, ...]
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('ads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zone_id')->constrained('ad_zones')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 80);
            $table->string('image_path')->nullable();
            $table->string('target_url');
            $table->unsignedSmallInteger('days');
            $table->decimal('price', 10, 2)->default(0);
            $table->string('status', 12)->default('unpaid'); // unpaid|review|active|paused|rejected
            $table->string('payment_method', 12)->nullable(); // card|transfer|manual
            $table->string('payment_ref', 64)->nullable()->index();
            $table->decimal('paid_amount', 10, 2)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('billing_name', 60)->nullable();
            $table->string('billing_phone', 20)->nullable();
            $table->string('billing_address', 400)->nullable();
            $table->string('note', 500)->nullable();
            $table->string('admin_note', 500)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->timestamps();
            $table->index(['status', 'ends_at']);
        });

        // Starter zones; titles, sizes, slots and prices are edited in the admin panel.
        $zones = [
            ['top', 'Üst banner', 'Tüm sayfalarda menünün hemen altında görünür.', 728, 90, 1, [[7, 300], [30, 900], [90, 2250]]],
            ['sidebar', 'Ana sayfa sağ sütun', 'Ana sayfanın sağ sütununda görünür.', 300, 250, 2, [[7, 200], [30, 600], [90, 1500]]],
            ['thread', 'Konu içi (ilk mesaj altı)', 'Her konuda ilk mesajın altında görünür.', 728, 90, 1, [[7, 250], [30, 750], [90, 1900]]],
            ['bottom', 'Alt banner', 'Tüm sayfalarda içeriğin altında görünür.', 728, 90, 1, [[7, 150], [30, 450], [90, 1100]]],
        ];

        foreach ($zones as $order => [$position, $title, $description, $width, $height, $slots, $prices]) {
            DB::table('ad_zones')->insert([
                'position' => $position,
                'title' => $title,
                'description' => $description,
                'width' => $width,
                'height' => $height,
                'slots' => $slots,
                'prices' => json_encode(array_map(fn (array $p) => ['days' => $p[0], 'price' => $p[1]], $prices)),
                'is_active' => true,
                'display_order' => ($order + 1) * 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ads');
        Schema::dropIfExists('ad_zones');
    }
};
