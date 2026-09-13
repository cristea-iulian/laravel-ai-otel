<?php

declare(strict_types=1);

namespace CristeaIulian\AiOtel\Laravel;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Tool;

final class Names
{
    public static function agent(Agent $agent): string
    {
        return class_basename($agent);
    }

    /**
     * Mirrors how the SDK resolves a tool's name for the model.
     */
    public static function tool(Tool $tool): string
    {
        if (is_callable([$tool, 'name'])) {
            $name = $tool->name();

            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return class_basename($tool);
    }
}
