<?php

declare(strict_types=1);

namespace Literaj\AiOtel\Exceptions;

use RuntimeException;

final class MissingOpenTelemetrySdkException extends RuntimeException
{
    public static function forOtlpDriver(): self
    {
        return new self(
            'The ai-otel "otlp" driver found no OpenTelemetry TracerProvider registered globally and cannot bootstrap one '
            .'because the OpenTelemetry SDK is not installed. Either install it '
            .'(composer require open-telemetry/sdk open-telemetry/exporter-otlp), register a TracerProvider through '
            .'open-telemetry/opentelemetry-auto-laravel, or switch AI_OTEL_DRIVER to "log".'
        );
    }
}
