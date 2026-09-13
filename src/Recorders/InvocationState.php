<?php

declare(strict_types=1);

namespace Literaj\AiOtel\Recorders;

/**
 * @internal
 */
final class InvocationState
{
    public int $attempt = 1;

    public ?string $lastFinishReason = null;

    /** @var list<string> */
    public array $stepKeys = [];

    /** @var list<string> */
    public array $toolKeys = [];

    public function __construct(
        public readonly SpanHandle $handle,
        public readonly bool $stream,
    ) {}
}
