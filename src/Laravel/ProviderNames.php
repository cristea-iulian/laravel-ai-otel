<?php

declare(strict_types=1);

namespace CristeaIulian\AiOtel\Laravel;

/**
 * Maps Laravel AI driver names to `gen_ai.provider.name` values.
 */
final class ProviderNames
{
    /** @var array<string, string> */
    private const MAP = [
        'anthropic' => 'anthropic',
        'azure' => 'azure.ai.openai',
        'bedrock' => 'aws.bedrock',
        'cohere' => 'cohere',
        'deepseek' => 'deepseek',
        'eleven' => 'elevenlabs',
        'gemini' => 'gcp.gemini',
        'groq' => 'groq',
        'jina' => 'jina',
        'mistral' => 'mistral_ai',
        'ollama' => 'ollama',
        'openai' => 'openai',
        'openai-compatible' => 'openai',
        'openrouter' => 'openrouter',
        'voyageai' => 'voyageai',
        'xai' => 'x_ai',
    ];

    public static function semantic(string $driver): string
    {
        return self::MAP[$driver] ?? $driver;
    }
}
