<?php

declare(strict_types=1);

use CristeaIulian\AiOtel\Attributes\GenAi;
use CristeaIulian\AiOtel\Attributes\LaravelAi;
use Laravel\Ai\Embeddings;
use OpenTelemetry\API\Trace\SpanKind;

test('embeddings generation records an embeddings span', function (): void {
    Embeddings::fake();

    Embeddings::for(['first input', 'second input'])->generate();

    $span = $this->spansNamed('embeddings ')->sole();
    $attributes = $this->attributes($span);

    expect($span->getKind())->toBe(SpanKind::KIND_CLIENT)
        ->and($span->getName())->toBe('embeddings '.$attributes[GenAi::REQUEST_MODEL])
        ->and($attributes[GenAi::OPERATION_NAME])->toBe('embeddings')
        ->and($attributes[GenAi::PROVIDER_NAME])->toBe('openai')
        ->and($attributes[LaravelAi::EMBEDDINGS_INPUT_COUNT])->toBe(2)
        ->and($attributes)->toHaveKey(GenAi::USAGE_INPUT_TOKENS)
        ->and($span->getEndEpochNanos())->toBeGreaterThanOrEqual($span->getStartEpochNanos());
});
