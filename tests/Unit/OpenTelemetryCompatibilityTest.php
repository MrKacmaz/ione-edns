<?php

use OpenTelemetry\SemConv\Incubating\Attributes\MessagingIncubatingAttributes;

/*
 * opentelemetry-auto-laravel 1.10 reads MESSAGING_SYSTEM when a job is pushed. Without
 * it (open-telemetry/sem-conv 1.45 dropped it) the hook fails before it opens its span
 * but still closes one afterwards: the caller's span, so a request that queues a job
 * loses its trace context and every delivery attempt its notification.deliver span.
 * sem-conv is pinned for this reason; see docs/backlog.md.
 */
test('the semantic conventions still define what the Laravel queue instrumentation reads', function () {
    expect(defined(MessagingIncubatingAttributes::class.'::MESSAGING_SYSTEM'))->toBeTrue();
});
