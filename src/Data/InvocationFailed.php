<?php

declare(strict_types=1);

namespace Literaj\AiOtel\Data;

use Throwable;

final readonly class InvocationFailed
{
    public function __construct(
        public string $invocationId,
        public Throwable $exception,
    ) {}
}
