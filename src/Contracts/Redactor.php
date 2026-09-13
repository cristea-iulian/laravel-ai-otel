<?php

declare(strict_types=1);

namespace Literaj\AiOtel\Contracts;

/**
 * Rewrites captured content before it is written to telemetry.
 *
 * Only invoked when content capture is enabled. Return an empty string to
 * drop the value entirely.
 */
interface Redactor
{
    /**
     * @param  string  $attribute  The attribute the content is about to be recorded under, e.g. "gen_ai.input.messages".
     */
    public function redact(string $content, string $attribute): string;
}
