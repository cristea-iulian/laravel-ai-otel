<?php

declare(strict_types=1);

namespace CristeaIulian\AiOtel\Data;

final readonly class Usage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheReadInputTokens = 0,
        public int $cacheWriteInputTokens = 0,
        public int $reasoningOutputTokens = 0,
    ) {}

    public function isEmpty(): bool
    {
        return $this->inputTokens === 0
            && $this->outputTokens === 0
            && $this->cacheReadInputTokens === 0
            && $this->cacheWriteInputTokens === 0
            && $this->reasoningOutputTokens === 0;
    }
}
