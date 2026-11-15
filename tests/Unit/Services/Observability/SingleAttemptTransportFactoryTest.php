<?php

use App\Services\Observability\SingleAttemptTransportFactory;
use OpenTelemetry\SDK\Common\Export\TransportFactoryInterface;
use OpenTelemetry\SDK\Common\Export\TransportInterface;

test('builds the transport as asked, but without retries', function () {
    $inner = new class implements TransportFactoryInterface
    {
        /** @var list<mixed> */
        public array $arguments = [];

        public function create(string $endpoint, string $contentType, array $headers = [], $compression = null, float $timeout = 10., int $retryDelay = 100, int $maxRetries = 3, ?string $cacert = null, ?string $cert = null, ?string $key = null): TransportInterface
        {
            $this->arguments = func_get_args();

            return Mockery::mock(TransportInterface::class);
        }
    };

    (new SingleAttemptTransportFactory($inner))->create('http://jaeger:4318/v1/traces', 'application/x-protobuf', ['X-Team' => 'ops'], 'gzip', 1.0, 200, 3);

    expect($inner->arguments)->toBe(['http://jaeger:4318/v1/traces', 'application/x-protobuf', ['X-Team' => 'ops'], 'gzip', 1.0, 200, 0, null, null, null]);
});
