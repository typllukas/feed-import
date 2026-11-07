<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            // exact, as the change check compares SKUs in PHP; the default collation would match acm-001 to ACM-001
            $table->string('sku', 64)->collation('utf8mb4_bin');
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->char('currency', 3);
            $table->unsignedInteger('stock');
            // raised by every upsert, so a consumer can drop an older message
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            // identity of a product within a partner, and the index MySQL upsert matches on
            $table->unique(['partner_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
