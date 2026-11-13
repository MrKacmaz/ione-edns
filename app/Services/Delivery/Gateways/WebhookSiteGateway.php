<?php

namespace App\Services\Delivery\Gateways;

use App\Data\GatewayResponseData;
use App\Exceptions\GatewayNotConfiguredException;
use App\Models\Notification;
use App\Services\Delivery\Contracts\NotificationGateway;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Delivers notifications to a webhook.site endpoint that simulates the provider.
 *
 * Request:  POST {url} {"to": "...", "channel": "sms", "content": "..."}
 * Response: 202 {"messageId": "...", "status": "accepted", "timestamp": "..."}
 */
final readonly class WebhookSiteGateway implements NotificationGateway
{
    public function __construct(
        private HttpFactory $http,
        #[Config('notifications.gateway.url')] private ?string $url,
        #[Config('notifications.gateway.timeout_seconds')] private int $timeoutSeconds,
        #[Config('notifications.gateway.connect_timeout_seconds')] private int $connectTimeoutSeconds,
    ) {}

    /**
     * Hand one notification to the provider and classify its answer.
     *
     * The notification id is sent as the idempotency key, so a provider that
     * honours it does not deliver twice when an attempt is retried after a timeout.
     *
     * @throws GatewayNotConfiguredException
     */
    public function send(Notification $notification): GatewayResponseData
    {
        if ($this->url === null || $this->url === '') {
            throw GatewayNotConfiguredException::missingUrl();
        }

        try {
            $response = $this->http
                ->connectTimeout($this->connectTimeoutSeconds)
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->withHeaders(array_filter([
                    'Idempotency-Key' => $notification->id,
                    'X-Correlation-ID' => $notification->correlation_id,
                ]))
                ->post($this->url, [
                    'to' => $notification->recipient,
                    'channel' => $notification->channel->value,
                    'content' => $notification->content,
                ]);
        } catch (ConnectionException $exception) {
            return GatewayResponseData::retryable(
                __('notifications.errors.unreachable', ['reason' => $exception->getMessage()]),
            );
        }

        if ($response->successful()) {
            $messageId = $response->json('messageId');

            return GatewayResponseData::accepted($response->status(), is_string($messageId) ? $messageId : null);
        }

        $error = __('notifications.errors.http_status', ['status' => $response->status()]);

        if ($response->tooManyRequests() || $response->serverError()) {
            return GatewayResponseData::retryable($error, $response->status(), $this->retryAfterSeconds($response));
        }

        return GatewayResponseData::permanent($error, $response->status());
    }

    /**
     * Read the provider's Retry-After header, given either in seconds or as an HTTP date.
     */
    private function retryAfterSeconds(Response $response): ?int
    {
        $retryAfter = trim($response->header('Retry-After'));

        if ($retryAfter === '') {
            return null;
        }

        if (ctype_digit($retryAfter)) {
            return (int) $retryAfter;
        }

        try {
            return max(0, (int) ceil(now()->diffInSeconds(CarbonImmutable::parse($retryAfter), false)));
        } catch (Throwable) {
            return null;
        }
    }
}
