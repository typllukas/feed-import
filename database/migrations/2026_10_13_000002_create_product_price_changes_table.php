<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'price_history';

    public function up(): void
    {
        Schema::create('product_price_changes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('partner_code', 32);
            $table->string('sku', 64)->collation('utf8mb4_bin');
            $table->decimal('price', 10, 2);
            $table->char('currency', 3);
            $table->unsignedBigInteger('feed_import_id');
            $table->timestamp('created_at');

            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_changes');
    }
};
