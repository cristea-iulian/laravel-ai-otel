<?php

declare(strict_types=1);

namespace CristeaIulian\AiOtel\Data;

final readonly class InvocationEnded
{
    /**
     * @param  array<array-key, mixed>|null  $outputMessages  Output messages in GenAI content format, only when content capture is enabled.
     */
    public function __construct(
        public string $invocationId,
        public Usage $usage,
        public ?string $conversationId = null,
        public ?string $responseModel = null,
        public ?string $responseProvider = null,
        public bool $structured = false,
        public int $toolCalls = 0,
        public int $pendingApprovals = 0,
        public ?array $outputMessages = null,
    ) {}
}
