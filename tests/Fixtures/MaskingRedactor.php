<?php

declare(strict_types=1);

namespace Literaj\AiOtel\Tests\Fixtures;

use Literaj\AiOtel\Contracts\Redactor;

class MaskingRedactor implements Redactor
{
    public function redact(string $content, string $attribute): string
    {
        return str_replace('secret', '[redacted]', $content);
    }
}
