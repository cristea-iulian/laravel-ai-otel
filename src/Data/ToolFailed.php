<?php

declare(strict_types=1);

namespace CristeaIulian\AiOtel\Data;

use Throwable;

final readonly class ToolFailed
{
    public function __construct(
        public string $invocationId,
        public string $toolInvocationId,
        public Throwable $exception,
        public float $durationMs,
    ) {}
}
