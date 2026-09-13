<?php

declare(strict_types=1);

namespace CristeaIulian\AiOtel\Laravel;

use CristeaIulian\AiOtel\Data\Usage;
use Laravel\Ai\Responses\Data\Usage as SdkUsage;

final class UsageMapper
{
    public static function from(SdkUsage $usage): Usage
    {
        return new Usage(
            inputTokens: $usage->promptTokens,
            outputTokens: $usage->completionTokens,
            cacheReadInputTokens: $usage->cacheReadInputTokens,
            cacheWriteInputTokens: $usage->cacheWriteInputTokens,
            reasoningOutputTokens: $usage->reasoningTokens,
        );
    }
}
