<?php

declare(strict_types=1);

namespace CristeaIulian\AiOtel\Recorders;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ScopeInterface;

/**
 * @internal
 */
final class SpanHandle
{
    public function __construct(
        public readonly SpanInterface $span,
        public readonly ContextInterface $context,
        public readonly ?ScopeInterface $scope,
    ) {}

    public function end(?int $endEpochNanos = null): void
    {
        $this->scope?->detach();
        $this->span->end($endEpochNanos);
    }
}
