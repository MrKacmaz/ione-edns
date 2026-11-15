<?php

/*
 * Registers the application's span exporter with the OpenTelemetry SDK.
 *
 * The SDK configures itself from OTEL_* environment variables while Composer
 * autoloads, before Laravel boots, so the exporter is registered from Composer's
 * "files" autoload rather than from a service provider.
 */

use App\Services\Observability\ResilientOtlpSpanExporterFactory;
use OpenTelemetry\SDK\Registry;

Registry::registerSpanExporterFactory(ResilientOtlpSpanExporterFactory::NAME, ResilientOtlpSpanExporterFactory::class);
