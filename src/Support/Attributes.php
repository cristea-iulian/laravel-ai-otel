<?php

declare(strict_types=1);

namespace CristeaIulian\AiOtel\Support;

use CristeaIulian\AiOtel\Attributes\GenAi;
use CristeaIulian\AiOtel\Data\Usage;
use Throwable;

/**
 * Small helpers shared by the recorders.
 */
final class Attributes
{
    /**
     * Keep only values a span attribute can hold, dropping nulls so optional
     * attributes are simply absent.
     *
     * @param  array<non-empty-string, mixed>  $attributes
     * @return array<non-empty-string, array<mixed>|bool|float|int|string>
     */
    public static function filter(array $attributes): array
    {
        $filtered = [];

        foreach ($attributes as $key => $value) {
            if (is_scalar($value) || is_array($value)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * @return array<non-empty-string, int>
     */
    public static function usage(Usage $usage): array
    {
        if ($usage->isEmpty()) {
            return [];
        }

        $attributes = [
            GenAi::USAGE_INPUT_TOKENS => $usage->inputTokens,
            GenAi::USAGE_OUTPUT_TOKENS => $usage->outputTokens,
        ];

        if ($usage->cacheReadInputTokens > 0) {
            $attributes[GenAi::USAGE_CACHE_READ_INPUT_TOKENS] = $usage->cacheReadInputTokens;
        }

        if ($usage->cacheWriteInputTokens > 0) {
            $attributes[GenAi::USAGE_CACHE_WRITE_INPUT_TOKENS] = $usage->cacheWriteInputTokens;
        }

        if ($usage->reasoningOutputTokens > 0) {
            $attributes[GenAi::USAGE_REASONING_OUTPUT_TOKENS] = $usage->reasoningOutputTokens;
        }

        return $attributes;
    }

    /**
     * @return array<non-empty-string, string>
     */
    public static function error(Throwable $exception): array
    {
        return [GenAi::ERROR_TYPE => $exception::class];
    }

    /**
     * Serialize a content structure for a span attribute. Spans in the PHP
     * SDK only carry primitive (or primitive-list) values, so structured
     * content is recorded as a JSON string, as the GenAI conventions allow.
     *
     * @param  array<array-key, mixed>|null  $value
     */
    public static function json(?array $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $encoded === false ? null : $encoded;
    }
}
