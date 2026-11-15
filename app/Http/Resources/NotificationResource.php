<?php

namespace App\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Notification
 */
class NotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->batch_id,
            'template_id' => $this->template_id,
            'channel' => $this->channel,
            'priority' => $this->priority,
            'status' => $this->status,
            'recipient' => $this->recipient,
            'content' => $this->content,
            'idempotency_key' => $this->idempotency_key,
            'correlation_id' => $this->correlation_id,
            'trace_id' => $this->trace_id,
            'attempts' => $this->attempts,
            'provider_message_id' => $this->provider_message_id,
            'last_error' => $this->last_error,
            'scheduled_at' => $this->scheduled_at,
            'queued_at' => $this->queued_at,
            'sent_at' => $this->sent_at,
            'failed_at' => $this->failed_at,
            'cancelled_at' => $this->cancelled_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
