<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\FeedImport;
use App\Models\Partner;
use App\Models\Product;
use App\Models\RejectedRow;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Admin',
            'email' => 'demo@feed-import.test',
            'password' => 'feed-import-demo',
        ]);

        Partner::factory()
            ->has(Product::factory()->count(20))
            ->has(FeedImport::factory()->count(2)->has(RejectedRow::factory()->count(3)))
            ->has(FeedImport::factory()->failed())
            ->create(['name' => 'Acme Tools', 'code' => 'acme']);

        Partner::factory()
            ->has(Product::factory()->count(40))
            ->create(['name' => 'Globex Parts', 'code' => 'globex']);
    }
}
