<?php

declare(strict_types=1);

namespace CristeaIulian\AiOtel\Data;

final readonly class EmbeddingsGenerated
{
    /**
     * @param  int  $startNanos  Start time as epoch nanoseconds.
     * @param  int  $endNanos  End time as epoch nanoseconds.
     */
    public function __construct(
        public string $invocationId,
        public string $providerName,
        public string $providerId,
        public string $providerDriver,
        public string $model,
        public int $dimensions,
        public int $inputCount,
        public int $inputTokens,
        public int $startNanos,
        public int $endNanos,
        public ?string $responseModel = null,
    ) {}
}
