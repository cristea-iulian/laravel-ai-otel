<?php

declare(strict_types=1);

use CristeaIulian\AiOtel\Attributes\LaravelAi;
use CristeaIulian\AiOtel\Contracts\Recorder;
use CristeaIulian\AiOtel\Recorders\SpanRecorder;
use CristeaIulian\AiOtel\Tests\Fixtures\Agents\ToolAgent;
use Laravel\Ai\Responses\Data\ToolCall;
use OpenTelemetry\Context\Context;

test('spans left open by an abandoned stream are closed and marked when the request terminates', function (): void {
    ToolAgent::fake([
        new ToolCall('call_1', 'EchoTool', ['text' => 'pong']),
        'Never consumed.',
    ]);

    $stream = (new ToolAgent)->stream('Echo pong');

    foreach ($stream as $event) {
        break; // Walk away after the first chunk...
    }

    expect($this->spans())->toHaveCount(0);

    $recorder = $this->app->make(Recorder::class);

    expect($recorder)->toBeInstanceOf(SpanRecorder::class);

    $recorder->endAll();

    $agent = $this->span('invoke_agent ToolAgent');

    expect($this->attributes($agent)[LaravelAi::ABANDONED])->toBeTrue()
        ->and($this->spansNamed('chat ')->sole()->getAttributes()->get(LaravelAi::ABANDONED))->toBeTrue()
        ->and(Context::getCurrent())->toBe(Context::getRoot());
});

test('the application terminating hook closes open spans', function (): void {
    ToolAgent::fake([
        new ToolCall('call_1', 'EchoTool', ['text' => 'pong']),
        'Never consumed.',
    ]);

    foreach ((new ToolAgent)->stream('Echo pong') as $event) {
        break;
    }

    $this->app->terminate();

    expect($this->spansNamed('invoke_agent ')->sole()->getAttributes()->get(LaravelAi::ABANDONED))->toBeTrue();
});
