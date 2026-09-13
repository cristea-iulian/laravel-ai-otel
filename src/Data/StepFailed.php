<?php

declare(strict_types=1);

namespace Literaj\AiOtel\Data;

use Throwable;

final readonly class StepFailed
{
    public function __construct(
        public string $invocationId,
        public int $stepNumber,
        public Throwable $exception,
        public float $durationMs,
    ) {}
}
