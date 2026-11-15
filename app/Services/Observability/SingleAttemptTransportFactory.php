<?php

namespace App\Services\Observability;

use OpenTelemetry\SDK\Common\Export\TransportFactoryInterface;
use OpenTelemetry\SDK\Common\Export\TransportInterface;

/**
 * Builds export transports that try a request only once.
 *
 * The SDK retries a failed export three times, so a hung backend costs four
 * timeouts; the ResilientSpanExporter decides when to try again instead.
 */
final readonly class SingleAttemptTransportFactory implements TransportFactoryInterface
{
    public function __construct(private TransportFactoryInterface $transportFactory) {}

    /**
     * @param  array<string, string|string[]>  $headers
     * @param  string|string[]|null  $compression
     * @return TransportInterface<string>
     */
    public function create(
        string $endpoint,
        string $contentType,
        array $headers = [],
        $compression = null,
        float $timeout = 10.,
        int $retryDelay = 100,
        int $maxRetries = 3,
        ?string $cacert = null,
        ?string $cert = null,
        ?string $key = null,
    ): TransportInterface {
        return $this->transportFactory->create($endpoint, $contentType, $headers, $compression, $timeout, $retryDelay, 0, $cacert, $cert, $key);
    }
}
