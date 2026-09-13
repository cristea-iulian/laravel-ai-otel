<?php

declare(strict_types=1);

namespace Literaj\AiOtel\Data;

final readonly class StepStarted
{
    /**
     * @param  array<array-key, mixed>|null  $instructions
     * @param  array<array-key, mixed>|null  $inputMessages
     */
    public function __construct(
        public string $invocationId,
        public int $stepNumber,
        public bool $isFinalStep,
        public string $providerName,
        public string $providerId,
        public string $providerDriver,
        public string $model,
        public bool $stream,
        public ?int $maxTokens = null,
        public ?float $temperature = null,
        public ?float $topP = null,
        public ?array $instructions = null,
        public ?array $inputMessages = null,
    ) {}
}
