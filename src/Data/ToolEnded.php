<?php

declare(strict_types=1);

namespace CristeaIulian\AiOtel\Data;

final readonly class ToolEnded
{
    /**
     * @param  string|null  $result  Only when content capture is enabled.
     */
    public function __construct(
        public string $invocationId,
        public string $toolInvocationId,
        public float $durationMs,
        public ?string $result = null,
    ) {}
}
