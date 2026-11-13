<?php

namespace App\Http\Resources;

use App\Enums\NotificationStatus;
use App\Models\NotificationBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NotificationBatch
 */
class NotificationBatchResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $statusCounts = $this->statusCounts();

        $unfinishedCount = array_map(static fn (NotificationStatus $status): string => $status->value, NotificationStatus::final())
                |> array_flip(...)
                |> (static fn ($x) => array_diff_key($statusCounts, $x))
                |> array_sum(...);

        return [
            'id' => $this->id,
            'total' => array_sum($statusCounts),
            'status_counts' => $statusCounts,
            'is_finished' => $unfinishedCount === 0,
            'created_at' => $this->created_at,
        ];
    }
}
