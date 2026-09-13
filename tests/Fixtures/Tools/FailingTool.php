<?php

declare(strict_types=1);

namespace Literaj\AiOtel\Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;

class FailingTool implements Tool
{
    public function description(): string
    {
        return 'Always fails.';
    }

    public function handle(Request $request): string
    {
        throw new RuntimeException('The tool exploded.');
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
