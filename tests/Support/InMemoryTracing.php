<?php

namespace Tests\Support;

use Illuminate\Contracts\Container\Container;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use RuntimeException;

/**
 * A real OpenTelemetry tracer whose finished spans are kept in memory, for assertions.
 *
 * The application's tracer is a no-op while tracing is off, as in the test suite;
 * binding this one records the spans the code under test creates.
 */
final class InMemoryTracing
{
    private readonly InMemoryExporter $exporter;

    private readonly TracerProvider $provider;

    private function __construct()
    {
        $this->exporter = new InMemoryExporter;
        $this->provider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
    }

    /**
     * Record the spans of the application's tracer from now on.
     */
    public static function bind(Container $container): self
    {
        $tracing = new self;
        $container->instance(TracerInterface::class, $tracing->tracer());

        return $tracing;
    }

    public function tracer(): TracerInterface
    {
        return $this->provider->getTracer('tests');
    }

    /**
     * Get the finished spans with the given name, in the order they ended.
     *
     * @return list<SpanDataInterface>
     */
    public function spansNamed(string $name): array
    {
        return array_values(array_filter(
            $this->exporter->getSpans(),
            fn (SpanDataInterface $span): bool => $span->getName() === $name,
        ));
    }

    /**
     * Get the only finished span with the given name.
     */
    public function soleSpanNamed(string $name): SpanDataInterface
    {
        $spans = $this->spansNamed($name);

        if (count($spans) !== 1) {
            throw new RuntimeException('Expected one ['.$name.'] span, found '.count($spans).'.');
        }

        return $spans[0];
    }
}
