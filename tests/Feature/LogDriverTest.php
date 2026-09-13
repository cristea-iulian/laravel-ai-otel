<?php

declare(strict_types=1);

use CristeaIulian\AiOtel\Attributes\GenAi;
use CristeaIulian\AiOtel\Attributes\LaravelAi;
use CristeaIulian\AiOtel\Contracts\Recorder;
use CristeaIulian\AiOtel\Recorders\LogRecorder;
use CristeaIulian\AiOtel\Tests\Fixtures\Agents\ToolAgent;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Responses\Data\ToolCall;

test('the log driver writes one entry per completed operation', function (): void {
    config()->set('ai-otel.driver', 'log');
    config()->set('ai-otel.drivers.log.level', 'debug');

    $this->app->forgetInstance(Recorder::class);

    expect($this->app->make(Recorder::class))->toBeInstanceOf(LogRecorder::class);

    $logged = [];

    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event;
    });

    ToolAgent::fake([
        new ToolCall('call_1', 'EchoTool', ['text' => 'ping']),
        'Done.',
    ]);

    (new ToolAgent)->prompt('Echo ping');

    expect(array_map(fn (MessageLogged $event) => $event->message, $logged))->toBe(['chat', 'execute_tool', 'chat', 'invoke_agent'])
        ->and($logged[0]->level)->toBe('debug')
        ->and($logged[0]->context[GenAi::RESPONSE_FINISH_REASONS])->toBe(['tool_calls'])
        ->and($logged[1]->context[GenAi::TOOL_NAME])->toBe('EchoTool')
        ->and($logged[1]->context)->toHaveKey(LaravelAi::DURATION_MS)
        ->and($logged[3]->context[GenAi::AGENT_NAME])->toBe('ToolAgent')
        ->and($logged[3]->context)->not->toHaveKey(GenAi::INPUT_MESSAGES)
        ->and($this->spans())->toHaveCount(0);
});
