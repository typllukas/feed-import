<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RejectedRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RejectedRow
 */
final class RejectedRowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'line_number' => $this->line_number,
            'message' => $this->message,
            'row' => $this->row,
            'feed_line' => $this->feed_line,
        ];
    }
}
