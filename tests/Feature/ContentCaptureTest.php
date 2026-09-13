<?php

declare(strict_types=1);

use Laravel\Ai\Responses\Data\ToolCall;
use Literaj\AiOtel\Attributes\GenAi;
use Literaj\AiOtel\Support\ContentCapture;
use Literaj\AiOtel\Tests\Fixtures\Agents\ToolAgent;
use Literaj\AiOtel\Tests\Fixtures\MaskingRedactor;

function decode(string $json): mixed
{
    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

test('content is not captured unless enabled', function (): void {
    ToolAgent::fake([
        new ToolCall('call_1', 'EchoTool', ['text' => 'ping']),
        'Done.',
    ]);

    (new ToolAgent)->prompt('Echo ping');

    foreach ($this->spans() as $span) {
        $attributes = $this->attributes($span);

        expect($attributes)->not->toHaveKey(GenAi::INPUT_MESSAGES)
            ->and($attributes)->not->toHaveKey(GenAi::OUTPUT_MESSAGES)
            ->and($attributes)->not->toHaveKey(GenAi::SYSTEM_INSTRUCTIONS)
            ->and($attributes)->not->toHaveKey(GenAi::TOOL_CALL_ARGUMENTS)
            ->and($attributes)->not->toHaveKey(GenAi::TOOL_CALL_RESULT)
            ->and($attributes)->not->toHaveKey(GenAi::TOOL_DESCRIPTION);
    }
});

test('content is captured in the GenAI message format when enabled', function (): void {
    $this->app->instance(ContentCapture::class, new ContentCapture(enabled: true));

    ToolAgent::fake([
        new ToolCall('call_1', 'EchoTool', ['text' => 'ping']),
        'Done.',
    ]);

    (new ToolAgent)->prompt('Echo ping');

    $agent = $this->attributes($this->span('invoke_agent ToolAgent'));

    expect(decode($agent[GenAi::SYSTEM_INSTRUCTIONS]))->toBe([['type' => 'text', 'content' => 'Use the tools when asked.']])
        ->and(decode($agent[GenAi::INPUT_MESSAGES]))->toBe([['role' => 'user', 'parts' => [['type' => 'text', 'content' => 'Echo ping']]]])
        ->and(decode($agent[GenAi::OUTPUT_MESSAGES]))->toBe([['role' => 'assistant', 'parts' => [['type' => 'text', 'content' => 'Done.']]]]);

    [$first, $second] = $this->spansNamed('chat ')->map(fn ($span) => $this->attributes($span))->all();

    expect(decode($first[GenAi::INPUT_MESSAGES]))->toBe([['role' => 'user', 'parts' => [['type' => 'text', 'content' => 'Echo ping']]]])
        ->and(decode($first[GenAi::OUTPUT_MESSAGES]))->toBe([[
            'role' => 'assistant',
            'parts' => [['type' => 'tool_call', 'id' => 'call_1', 'name' => 'EchoTool', 'arguments' => ['text' => 'ping']]],
            'finish_reason' => 'tool_calls',
        ]]);

    $secondInput = decode($second[GenAi::INPUT_MESSAGES]);

    expect($secondInput)->toHaveCount(3)
        ->and($secondInput[1]['role'])->toBe('assistant')
        ->and($secondInput[1]['parts'][0]['type'])->toBe('tool_call')
        ->and($secondInput[2])->toBe(['role' => 'tool', 'parts' => [['type' => 'tool_call_response', 'id' => 'call_1', 'response' => 'echo: ping']]]);

    $tool = $this->attributes($this->span('execute_tool EchoTool'));

    expect(decode($tool[GenAi::TOOL_CALL_ARGUMENTS]))->toBe(['text' => 'ping'])
        ->and($tool[GenAi::TOOL_CALL_RESULT])->toBe('echo: ping')
        ->and($tool[GenAi::TOOL_DESCRIPTION])->toBe('Echoes the given text back.');
});

test('captured content is truncated and redacted', function (): void {
    $this->app->instance(ContentCapture::class, new ContentCapture(enabled: true, maxLength: 20, redactor: new MaskingRedactor));

    ToolAgent::fake([
        new ToolCall('call_1', 'EchoTool', ['text' => 'my secret value']),
        'Done.',
    ]);

    (new ToolAgent)->prompt('This prompt is longer than twenty characters, and holds a secret.');

    $agent = $this->attributes($this->span('invoke_agent ToolAgent'));
    $tool = $this->attributes($this->span('execute_tool EchoTool'));

    expect(decode($agent[GenAi::INPUT_MESSAGES])[0]['parts'][0]['content'])->toBe('This prompt is longe'.ContentCapture::TRUNCATION_MARKER)
        ->and(decode($tool[GenAi::TOOL_CALL_ARGUMENTS]))->toBe(['text' => 'my [redacted] value'])
        ->and($tool[GenAi::TOOL_CALL_RESULT])->toBe('echo: my [redacted] '.ContentCapture::TRUNCATION_MARKER);
});
