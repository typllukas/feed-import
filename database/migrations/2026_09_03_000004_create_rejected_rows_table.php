<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rejected_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feed_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('message');
            $table->json('row');
            $table->timestamps();

            // the import page lists them by line, and an import can reject every row of its feed
            $table->index(['feed_import_id', 'line_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rejected_rows');
    }
};
