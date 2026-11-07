<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FeedImportStatus;
use Database\Factories\FeedImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property-read Partner $partner
 */
#[Fillable(['partner_id', 'status', 'file_path', 'total_row_count', 'imported_row_count', 'rejected_row_count', 'started_at', 'finished_at', 'failure_reason'])]
class FeedImport extends Model
{
    /** @use HasFactory<FeedImportFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FeedImportStatus::class,
            'total_row_count' => 'integer',
            'imported_row_count' => 'integer',
            'rejected_row_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * @return HasMany<RejectedRow, $this>
     */
    public function rejectedRows(): HasMany
    {
        return $this->hasMany(RejectedRow::class);
    }

    public function markProcessing(): void
    {
        $this->update([
            'status' => FeedImportStatus::Processing,
            'started_at' => now(),
            'finished_at' => null,
            'failure_reason' => null,
            'total_row_count' => null,
            'imported_row_count' => 0,
            'rejected_row_count' => 0,
        ]);
    }

    public function countWrittenRows(int $importedRowCount, int $rejectedRowCount): void
    {
        // a query, since a transaction retried at commit finds the model already saved and update() would write nothing
        self::query()->whereKey($this->id)->update([
            'imported_row_count' => $importedRowCount,
            'rejected_row_count' => $rejectedRowCount,
        ]);
    }

    public function markCompleted(int $totalRowCount): void
    {
        $this->update([
            'status' => FeedImportStatus::Completed,
            'total_row_count' => $totalRowCount,
            'finished_at' => now(),
        ]);
    }

    public function markFailed(string $failureReason): void
    {
        $this->update([
            'status' => FeedImportStatus::Failed,
            'failure_reason' => Str::limit($failureReason, 1000),
            'finished_at' => now(),
        ]);
    }

    public function markSuperseded(): void
    {
        $this->update([
            'status' => FeedImportStatus::Superseded,
            'finished_at' => now(),
        ]);
    }

    public function isSupersededByNewerImport(): bool
    {
        return self::query()
            ->where('partner_id', $this->partner_id)
            ->where('id', '>', $this->id)
            ->whereIn('status', [FeedImportStatus::Processing, FeedImportStatus::Completed])
            ->exists();
    }

    /**
     * @param  Builder<FeedImport>  $query
     */
    #[Scope]
    protected function recent(Builder $query): void
    {
        $query->latest('created_at')->latest('id');
    }
}
