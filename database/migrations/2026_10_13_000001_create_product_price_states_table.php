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
        // no foreign key to products: they are in the importer's database
        Schema::create('product_price_states', function (Blueprint $table): void {
            $table->id();
            // not partner and sku: a partner deleted and created again restarts its versions at 1
            $table->unsignedBigInteger('product_id')->unique();
            $table->unsignedInteger('version');
            $table->decimal('price', 10, 2);
            $table->char('currency', 3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_states');
    }
};
