<?php

declare(strict_types=1);

namespace CristeaIulian\AiOtel\Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

class OrchestratorAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'Delegate research to the research agent.';
    }

    public function tools(): iterable
    {
        return [new ResearchAgent];
    }
}
