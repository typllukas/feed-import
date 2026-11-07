<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FeedImportStatus;
use App\Models\FeedImport;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeedImport>
 */
class FeedImportFactory extends Factory
{
    /**
     * @return array<model-property<FeedImport>, mixed>
     */
    public function definition(): array
    {
        $startedAt = fake()->dateTimeBetween('-10 days', '-1 hour');

        return [
            'partner_id' => Partner::factory(),
            'status' => FeedImportStatus::Completed,
            'file_path' => 'feeds/seeded.csv',
            'total_row_count' => 120,
            'imported_row_count' => 117,
            'rejected_row_count' => 3,
            'started_at' => $startedAt,
            'finished_at' => (clone $startedAt)->modify('+20 seconds'),
            'created_at' => $startedAt,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => FeedImportStatus::Pending,
            'total_row_count' => null,
            'imported_row_count' => 0,
            'rejected_row_count' => 0,
            'started_at' => null,
            'finished_at' => null,
            'created_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => FeedImportStatus::Failed,
            'file_path' => 'feeds/missing.csv',
            'total_row_count' => null,
            'imported_row_count' => 0,
            'rejected_row_count' => 0,
            'failure_reason' => 'Feed file feeds/missing.csv was not found on the feed disk.',
        ]);
    }
}
