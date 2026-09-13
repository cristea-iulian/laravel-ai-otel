<?php

declare(strict_types=1);

namespace CristeaIulian\AiOtel\Data;

final readonly class ToolStarted
{
    /**
     * @param  array<array-key, mixed>|null  $arguments  Only when content capture is enabled.
     */
    public function __construct(
        public string $invocationId,
        public string $toolInvocationId,
        public string $toolName,
        public string $toolClass,
        public string $agentName,
        public ?string $toolCallId = null,
        public ?string $description = null,
        public ?array $arguments = null,
    ) {}
}
