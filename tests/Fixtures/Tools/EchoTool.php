<?php

declare(strict_types=1);

namespace CristeaIulian\AiOtel\Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class EchoTool implements Tool
{
    public function description(): string
    {
        return 'Echoes the given text back.';
    }

    public function handle(Request $request): string
    {
        return 'echo: '.$request['text'];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->required(),
        ];
    }
}
