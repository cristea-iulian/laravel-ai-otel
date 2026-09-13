<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Literaj\AiOtel\Attributes\GenAi;
use Literaj\AiOtel\Attributes\LaravelAi;
use Literaj\AiOtel\Tests\Fixtures\Agents\AssistantAgent;
use OpenTelemetry\API\Trace\StatusCode;

function chatCompletion(string $content): array
{
    return [
        'id' => 'chatcmpl-1',
        'object' => 'chat.completion',
        'created' => 1,
        'model' => 'test-model',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => $content],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 2, 'total_tokens' => 6],
    ];
}

test('a failover to another provider is recorded on the agent span', function (): void {
    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->push(status: 429)
        ->push(chatCompletion('Hello from the backup.'));

    $response = (new AssistantAgent)->prompt('Hi', provider: ['primary', 'backup']);

    expect($response->text)->toBe('Hello from the backup.');

    $agent = $this->span('invoke_agent AssistantAgent');
    $attributes = $this->attributes($agent);

    expect($this->spansNamed('invoke_agent ')->count())->toBe(1)
        ->and($agent->getStatus()->getCode())->toBe(StatusCode::STATUS_UNSET)
        ->and($attributes[LaravelAi::ATTEMPT])->toBe(2)
        ->and($attributes[LaravelAi::PROVIDER_NAME])->toBe('backup')
        ->and($attributes[GenAi::PROVIDER_NAME])->toBe('groq')
        ->and(array_map(fn ($event) => $event->getName(), $agent->getEvents()))->toBe([LaravelAi::EVENT_FAILOVER, LaravelAi::EVENT_RETRY]);

    $chats = $this->spansNamed('chat ');

    expect($chats)->toHaveCount(2)
        ->and($chats[0]->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
        ->and($this->attributes($chats[0])[LaravelAi::PROVIDER_NAME])->toBe('primary')
        ->and($chats[1]->getStatus()->getCode())->toBe(StatusCode::STATUS_UNSET)
        ->and($this->attributes($chats[1])[LaravelAi::PROVIDER_NAME])->toBe('backup')
        ->and($chats[1]->getParentSpanId())->toBe($agent->getSpanId());
});
