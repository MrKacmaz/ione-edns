<?php

namespace App\Http\Requests;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Rules\Iso8601DateRule;
use App\Rules\PaginationCursorRule;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            /** The earliest creation time (ISO 8601). An offset such as `+03:00` is honoured; a date alone means the start of that day in UTC. */
            'created_from' => ['sometimes', 'date', new Iso8601DateRule],
            /** The latest creation time (ISO 8601). An offset is honoured; a date alone, such as `2026-11-12`, includes that whole day in UTC. */
            'created_to' => ['sometimes', 'date', new Iso8601DateRule],
            'per_page' => ['sometimes', 'integer', 'between:1,'.self::MAX_PER_PAGE],
            'cursor' => ['sometimes', 'string', new PaginationCursorRule(['created_at' => ['required', 'date'], 'id' => ['required', 'uuid']])],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * The range is checked on the times the filter uses, not on the raw input:
     * an end date without a time covers its whole day, so it may name the day
     * the start time falls on.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['created_from', 'created_to'])) {
                    return;
                }

                $createdFrom = $this->createdFrom();
                $createdTo = $this->createdTo();

                if ($createdFrom !== null && $createdTo !== null && $createdTo->lt($createdFrom)) {
                    $validator->errors()->add('created_to', __('validation.after_or_equal', [
                        'attribute' => $validator->getDisplayableAttribute('created_to'),
                        'date' => $validator->getDisplayableAttribute('created_from'),
                    ]));
                }
            },
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
     * Get the earliest creation time to include, in UTC.
     *
     * A given offset is honoured; a bare date means the start of that day in UTC.
     */
    public function createdFrom(): ?CarbonInterface
    {
        return $this->date('created_from')?->utc();
    }

    /**
     * Get the latest creation time to include, in UTC.
     *
     * A given offset is honoured; a bare date includes that whole day in UTC.
     */
    public function createdTo(): ?CarbonInterface
    {
        $createdTo = $this->date('created_to')?->utc();

        return $createdTo !== null && $this->isBareDate('created_to') ? $createdTo->endOfDay() : $createdTo;
    }

    /**
     * Determine whether the input is a date without a time, such as "2026-11-12".
     */
    private function isBareDate(string $key): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->string($key)->trim()->toString()) === 1;
    }

    /**
     * Get the number of notifications per page.
     */
    public function perPage(): int
    {
        return $this->integer('per_page', self::DEFAULT_PER_PAGE);
    }
}
