<?php

namespace App\Http\Requests;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Rules\PaginationCursorRule;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListNotificationsRequest extends FormRequest
{
    public const int DEFAULT_PER_PAGE = 25;

    public const int MAX_PER_PAGE = 100;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(NotificationStatus::class)],
            'channel' => ['sometimes', Rule::enum(NotificationChannel::class)],
            'batch_id' => ['sometimes', 'uuid'],
            'created_from' => ['sometimes', 'date'],
            'created_to' => ['sometimes', 'date', 'after_or_equal:created_from'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.self::MAX_PER_PAGE],
            'cursor' => ['sometimes', 'string', new PaginationCursorRule(['created_at' => ['required', 'date'], 'id' => ['required', 'uuid']])],
        ];
    }

    /**
     * Get the status to filter by.
     */
    public function status(): ?NotificationStatus
    {
        return $this->enum('status', NotificationStatus::class);
    }

    /**
     * Get the channel to filter by.
     */
    public function channel(): ?NotificationChannel
    {
        return $this->enum('channel', NotificationChannel::class);
    }

    /**
     * Get the batch to filter by.
     */
    public function batchId(): ?string
    {
        return $this->filled('batch_id') ? $this->string('batch_id')->toString() : null;
    }

    /**
     * Get the earliest creation time to include.
     */
    public function createdFrom(): ?CarbonInterface
    {
        return $this->date('created_from');
    }

    /**
     * Get the latest creation time to include.
     */
    public function createdTo(): ?CarbonInterface
    {
        return $this->date('created_to');
    }

    /**
     * Get the number of notifications per page.
     */
    public function perPage(): int
    {
        return $this->integer('per_page', self::DEFAULT_PER_PAGE);
    }
}
