<?php

declare(strict_types=1);

namespace Literaj\AiOtel\Data;

use Throwable;

final readonly class Failover
{
    public function __construct(
        public string $invocationId,
        public string $providerName,
        public string $providerId,
        public string $providerDriver,
        public string $model,
        public Throwable $exception,
    ) {}
}
