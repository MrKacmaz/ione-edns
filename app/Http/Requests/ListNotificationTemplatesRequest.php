<?php

namespace App\Http\Requests;

use App\Enums\NotificationChannel;
use App\Rules\PaginationCursorRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListNotificationTemplatesRequest extends FormRequest
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
            'channel' => ['sometimes', Rule::enum(NotificationChannel::class)],
            'per_page' => ['sometimes', 'integer', 'between:1,'.self::MAX_PER_PAGE],
            'cursor' => ['sometimes', 'string', new PaginationCursorRule(['name' => ['required', 'string'], 'id' => ['required', 'uuid']])],
        ];
    }

    /**
     * Get the channel to filter by.
     */
    public function channel(): ?NotificationChannel
    {
        return $this->enum('channel', NotificationChannel::class);
    }

    /**
     * Get the number of templates per page.
     */
    public function perPage(): int
    {
        return $this->integer('per_page', self::DEFAULT_PER_PAGE);
    }
}
