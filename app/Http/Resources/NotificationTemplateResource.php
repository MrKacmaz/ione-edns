<?php

namespace App\Http\Resources;

use App\Models\NotificationTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NotificationTemplate
 */
class NotificationTemplateResource extends JsonResource
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
            'name' => $this->name,
            'channel' => $this->channel,
            'content' => $this->content,
            /** The variables a notification created from the template must give a value to. */
            'variables' => $this->variables(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
