<?php

namespace App\Rules\Concerns;

use App\Enums\NotificationChannel;
use App\Services\Delivery\Contracts\DeliveryChannel;
use Illuminate\Support\Str;

/**
 * Resolves the delivery channel of the notification an attribute belongs to, for both
 * a single notification (`recipient`) and an item of a batch (`notifications.3.recipient`).
 *
 * The using rule must hold the channel registry in a `$channels` property.
 */
trait ResolvesNotificationChannel
{
    /**
     * All the data under validation.
     *
     * @var array<array-key, mixed>
     */
    protected array $data = [];

    /**
     * Set the data under validation.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Get the delivery channel of the notification the attribute belongs to, if its channel is valid.
     */
    protected function deliveryChannelOf(string $attribute): ?DeliveryChannel
    {
        $channelValue = data_get($this->data, $this->siblingOf($attribute, 'channel'));
        $channel = is_string($channelValue) ? NotificationChannel::tryFrom($channelValue) : null;

        return $channel === null ? null : $this->channels->for($channel);
    }

    /**
     * Get the name of another attribute of the same notification, such as `notifications.3.channel`.
     */
    protected function siblingOf(string $attribute, string $sibling): string
    {
        return str_contains($attribute, '.')
            ? Str::beforeLast($attribute, '.').'.'.$sibling
            : $sibling;
    }
}
