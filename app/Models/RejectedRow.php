<?php

declare(strict_types=1);

namespace App\Models;

use App\Rules\FeedHeader;
use Database\Factories\RejectedRowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, string|list<string>> $row
 * @property-read string $feed_line
 */
#[Fillable(['feed_import_id', 'line_number', 'message', 'row'])]
class RejectedRow extends Model
{
    /** @use HasFactory<RejectedRowFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'row' => 'array',
        ];
    }

    /**
     * A row rejected before validation (not UTF-8, wrong column count) is stored under 'raw', since its fields have no names;
     * a named row is put back in the feed's column order, since MySQL's JSON type keeps an object's keys sorted by length.
     *
     * @return Attribute<string, never>
     */
    protected function feedLine(): Attribute
    {
        return Attribute::get(function (): string {
            /** @var list<string> $fields */
            $fields = isset($this->row['raw']) ? $this->row['raw'] : array_map(fn (string $column): string|array => $this->row[$column] ?? '', FeedHeader::COLUMNS);

            return implode(';', $fields);
        });
    }

    /**
     * @param  Builder<RejectedRow>  $query
     */
    #[Scope]
    protected function inLineOrder(Builder $query): void
    {
        $query->orderBy('line_number');
    }

    /**
     * @return BelongsTo<FeedImport, $this>
     */
    public function feedImport(): BelongsTo
    {
        return $this->belongsTo(FeedImport::class);
    }
}
