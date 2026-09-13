<?php

declare(strict_types=1);

namespace Literaj\AiOtel\Data;

final readonly class ApprovalRequested
{
    /**
     * @param  list<string>  $toolNames
     */
    public function __construct(
        public string $invocationId,
        public array $toolNames,
        public ?string $conversationId = null,
    ) {}
}
