<?php

declare(strict_types=1);

namespace CristeaIulian\AiOtel\Tests\Fixtures\Agents;

use CristeaIulian\AiOtel\Tests\Fixtures\Tools\EchoTool;
use CristeaIulian\AiOtel\Tests\Fixtures\Tools\FailingTool;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

#[MaxSteps(5)]
#[MaxTokens(256)]
#[Temperature(0.2)]
class ToolAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'Use the tools when asked.';
    }

    public function tools(): iterable
    {
        return [new EchoTool, new FailingTool];
    }
}
