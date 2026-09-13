<?php

declare(strict_types=1);

namespace CristeaIulian\AiOtel\Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class AssistantAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a concise assistant.';
    }
}
