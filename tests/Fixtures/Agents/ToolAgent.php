<?php

declare(strict_types=1);

namespace Literaj\AiOtel\Tests\Fixtures\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Literaj\AiOtel\Tests\Fixtures\Tools\EchoTool;
use Literaj\AiOtel\Tests\Fixtures\Tools\FailingTool;

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
