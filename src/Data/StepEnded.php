<?php

declare(strict_types=1);

namespace Literaj\AiOtel\Data;

final readonly class StepEnded
{
    /**
     * @param  list<array{id: string, name: string}>  $toolCalls
     * @param  array<array-key, mixed>|null  $outputMessages
     */
    public function __construct(
        public string $invocationId,
        public int $stepNumber,
        public string $finishReason,
        public Usage $usage,
        public float $durationMs,
        public array $toolCalls = [],
        public ?string $responseModel = null,
        public ?string $responseProvider = null,
        public bool $structured = false,
        public ?array $outputMessages = null,
    ) {}
}
