<?php

declare(strict_types=1);

use CristeaIulian\AiOtel\Attributes\GenAi;
use CristeaIulian\AiOtel\Attributes\LaravelAi;
use CristeaIulian\AiOtel\Tests\Fixtures\Agents\AssistantAgent;
use CristeaIulian\AiOtel\Tests\Fixtures\Agents\OrchestratorAgent;
use CristeaIulian\AiOtel\Tests\Fixtures\Agents\ResearchAgent;
use CristeaIulian\AiOtel\Tests\Fixtures\Agents\ToolAgent;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;

test('a prompt records an invoke_agent span with a chat span underneath', function (): void {
    AssistantAgent::fake([
        new TextResponse('Hello!', new Usage(promptTokens: 12, completionTokens: 3), new Meta('openai', 'gpt-test')),
    ]);

    $response = (new AssistantAgent)->prompt('Hi');

    expect($this->spans())->toHaveCount(2);

    $agent = $this->span('invoke_agent AssistantAgent');
    $chat = $this->spansNamed('chat ')->sole();

    expect($agent->getKind())->toBe(SpanKind::KIND_INTERNAL)
        ->and($chat->getKind())->toBe(SpanKind::KIND_CLIENT)
        ->and($chat->getParentSpanId())->toBe($agent->getSpanId())
        ->and($chat->getTraceId())->toBe($agent->getTraceId())
        ->and($agent->getStatus()->getCode())->toBe(StatusCode::STATUS_UNSET);

    $attributes = $this->attributes($agent);

    expect($attributes[GenAi::OPERATION_NAME])->toBe('invoke_agent')
        ->and($attributes[GenAi::AGENT_NAME])->toBe('AssistantAgent')
        ->and($attributes[GenAi::PROVIDER_NAME])->toBe('openai')
        ->and($attributes[GenAi::REQUEST_STREAM])->toBeFalse()
        ->and($attributes[GenAi::USAGE_INPUT_TOKENS])->toBe(12)
        ->and($attributes[GenAi::USAGE_OUTPUT_TOKENS])->toBe(3)
        ->and($attributes[GenAi::RESPONSE_MODEL])->toBe('gpt-test')
        ->and($attributes[GenAi::RESPONSE_FINISH_REASONS])->toBe(['stop'])
        ->and($attributes[LaravelAi::INVOCATION_ID])->toBe($response->invocationId)
        ->and($attributes[LaravelAi::AGENT_CLASS])->toBe(AssistantAgent::class)
        ->and($attributes[LaravelAi::PROVIDER_NAME])->toBe('openai')
        ->and($attributes)->not->toHaveKey(GenAi::INPUT_MESSAGES)
        ->and($attributes)->not->toHaveKey(GenAi::SYSTEM_INSTRUCTIONS)
        ->and($attributes)->not->toHaveKey(GenAi::OUTPUT_MESSAGES);

    $chatAttributes = $this->attributes($chat);

    expect($chat->getName())->toBe('chat '.$chatAttributes[GenAi::REQUEST_MODEL])
        ->and($chatAttributes[GenAi::OPERATION_NAME])->toBe('chat')
        ->and($chatAttributes[GenAi::PROVIDER_NAME])->toBe('openai')
        ->and($chatAttributes[GenAi::RESPONSE_FINISH_REASONS])->toBe(['stop'])
        ->and($chatAttributes[GenAi::USAGE_INPUT_TOKENS])->toBe(12)
        ->and($chatAttributes[LaravelAi::STEP_NUMBER])->toBe(0)
        ->and($chatAttributes[LaravelAi::INVOCATION_ID])->toBe($response->invocationId);
});

test('a tool calling run records one chat span per step and an execute_tool span per tool call', function (): void {
    ToolAgent::fake([
        new ToolCall('call_1', 'EchoTool', ['text' => 'ping']),
        'Done.',
    ]);

    $response = (new ToolAgent)->prompt('Echo ping');

    $agent = $this->span('invoke_agent ToolAgent');
    $chats = $this->spansNamed('chat ');
    $tool = $this->span('execute_tool EchoTool');

    expect($this->spans())->toHaveCount(4)
        ->and($chats)->toHaveCount(2)
        ->and($tool->getKind())->toBe(SpanKind::KIND_INTERNAL)
        ->and($tool->getParentSpanId())->toBe($agent->getSpanId());

    $first = $this->attributes($chats[0]);
    $second = $this->attributes($chats[1]);

    expect($first[LaravelAi::STEP_NUMBER])->toBe(0)
        ->and($first[GenAi::RESPONSE_FINISH_REASONS])->toBe(['tool_calls'])
        ->and($first[LaravelAi::STEP_TOOL_CALLS])->toBe(1)
        ->and($first[GenAi::REQUEST_MAX_TOKENS])->toBe(256)
        ->and($first[GenAi::REQUEST_TEMPERATURE])->toBe(0.2)
        ->and($second[LaravelAi::STEP_NUMBER])->toBe(1)
        ->and($second[GenAi::RESPONSE_FINISH_REASONS])->toBe(['stop']);

    $toolAttributes = $this->attributes($tool);

    expect($toolAttributes[GenAi::OPERATION_NAME])->toBe('execute_tool')
        ->and($toolAttributes[GenAi::TOOL_NAME])->toBe('EchoTool')
        ->and($toolAttributes[GenAi::TOOL_TYPE])->toBe('function')
        ->and($toolAttributes[GenAi::TOOL_CALL_ID])->toBe('call_1')
        ->and($toolAttributes[GenAi::AGENT_NAME])->toBe('ToolAgent')
        ->and($toolAttributes[LaravelAi::INVOCATION_ID])->toBe($response->invocationId)
        ->and($toolAttributes)->not->toHaveKey(GenAi::TOOL_CALL_ARGUMENTS)
        ->and($toolAttributes)->not->toHaveKey(GenAi::TOOL_CALL_RESULT)
        ->and($toolAttributes)->not->toHaveKey(GenAi::TOOL_DESCRIPTION);

    // Spans end in the order the work completes: step 0, the tool, step 1, the agent...
    expect($this->spans()->map->getName()->all())->toBe([
        $chats[0]->getName(), 'execute_tool EchoTool', $chats[1]->getName(), 'invoke_agent ToolAgent',
    ]);
});

test('a failing tool marks the tool span and the agent span as errors', function (): void {
    ToolAgent::fake([
        new ToolCall('call_1', 'FailingTool', []),
        'Never reached.',
    ]);

    expect(fn () => (new ToolAgent)->prompt('Explode'))->toThrow(RuntimeException::class, 'The tool exploded.');

    $tool = $this->span('execute_tool FailingTool');
    $agent = $this->span('invoke_agent ToolAgent');

    expect($tool->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
        ->and($this->attributes($tool)[GenAi::ERROR_TYPE])->toBe(RuntimeException::class)
        ->and($tool->getEvents())->toHaveCount(1)
        ->and($tool->getEvents()[0]->getName())->toBe('exception')
        ->and($agent->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
        ->and($this->attributes($agent)[GenAi::ERROR_TYPE])->toBe(RuntimeException::class)
        ->and($this->attributes($agent)[GenAi::RESPONSE_FINISH_REASONS])->toBe(['error']);
});

test('a streamed run records the same spans flagged as streaming', function (): void {
    ToolAgent::fake([
        new ToolCall('call_1', 'EchoTool', ['text' => 'pong']),
        'Streamed and done.',
    ]);

    $stream = (new ToolAgent)->stream('Echo pong');

    foreach ($stream as $event) {
        //
    }

    $agent = $this->span('invoke_agent ToolAgent');

    expect($this->spans())->toHaveCount(4)
        ->and($this->attributes($agent)[GenAi::REQUEST_STREAM])->toBeTrue()
        ->and($this->attributes($this->spansNamed('chat ')[0])[GenAi::REQUEST_STREAM])->toBeTrue()
        ->and($this->span('execute_tool EchoTool')->getParentSpanId())->toBe($agent->getSpanId());
});

test('a sub-agent used as a tool nests under the execute_tool span of its parent', function (): void {
    OrchestratorAgent::fake([
        new ToolCall('call_1', 'ResearchAgent', ['task' => 'Find things']),
        'Delegated.',
    ]);

    ResearchAgent::fake(['Research result.']);

    (new OrchestratorAgent)->prompt('Do research');

    $orchestrator = $this->span('invoke_agent OrchestratorAgent');
    $tool = $this->span('execute_tool ResearchAgent');
    $research = $this->span('invoke_agent ResearchAgent');

    expect($tool->getParentSpanId())->toBe($orchestrator->getSpanId())
        ->and($research->getParentSpanId())->toBe($tool->getSpanId())
        ->and($research->getTraceId())->toBe($orchestrator->getTraceId())
        ->and($this->attributes($research)[LaravelAi::PARENT_INVOCATION_ID])->toBe($this->attributes($orchestrator)[LaravelAi::INVOCATION_ID])
        ->and($this->attributes($research)[LaravelAi::PARENT_TOOL_INVOCATION_ID])->toBe($this->attributes($tool)[LaravelAi::TOOL_INVOCATION_ID]);

    // The research agent's own chat span hangs off the research agent, not the orchestrator...
    $researchChat = $this->spans()->first(fn ($span) => str_starts_with($span->getName(), 'chat ') && $span->getParentSpanId() === $research->getSpanId());

    expect($researchChat)->not->toBeNull();
});
