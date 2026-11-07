<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FeedImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FeedImport
 */
final class FeedImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'partner_id' => $this->partner_id,
            'status' => $this->status,
            'total_row_count' => $this->total_row_count,
            'imported_row_count' => $this->imported_row_count,
            'rejected_row_count' => $this->rejected_row_count,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at,
        ];
    }
}
