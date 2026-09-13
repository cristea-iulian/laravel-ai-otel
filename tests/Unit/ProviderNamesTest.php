<?php

declare(strict_types=1);

use Literaj\AiOtel\Laravel\ProviderNames;

test('laravel ai drivers map to GenAI provider names', function (string $driver, string $expected): void {
    expect(ProviderNames::semantic($driver))->toBe($expected);
})->with([
    ['anthropic', 'anthropic'],
    ['azure', 'azure.ai.openai'],
    ['bedrock', 'aws.bedrock'],
    ['gemini', 'gcp.gemini'],
    ['mistral', 'mistral_ai'],
    ['openai-compatible', 'openai'],
    ['xai', 'x_ai'],
    ['something-new', 'something-new'],
]);
