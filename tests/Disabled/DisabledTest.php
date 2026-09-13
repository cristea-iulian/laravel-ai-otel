<?php

declare(strict_types=1);

use CristeaIulian\AiOtel\Tests\Fixtures\Agents\AssistantAgent;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Events\PromptingAgent;

test('nothing is recorded and no listeners are registered when the package is disabled', function (): void {
    expect($this->app->make(Dispatcher::class)->hasListeners(PromptingAgent::class))->toBeFalse();

    AssistantAgent::fake(['Hello!']);

    (new AssistantAgent)->prompt('Hi');

    expect($this->spans())->toHaveCount(0);
});
