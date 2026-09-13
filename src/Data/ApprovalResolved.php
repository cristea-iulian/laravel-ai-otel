<?php

declare(strict_types=1);

namespace CristeaIulian\AiOtel\Data;

final readonly class ApprovalResolved
{
    public function __construct(
        public string $invocationId,
        public int $resolved,
        public int $denied,
        public ?string $conversationId = null,
    ) {}
}
