<?php

declare(strict_types=1);

namespace Literaj\AiOtel\Data;

final readonly class InvocationStarted
{
    /**
     * @param  string  $providerName  Provider name in GenAI convention terms, e.g. "anthropic" or "aws.bedrock".
     * @param  string  $providerId  The provider as configured in the application, e.g. "anthropic" or "primary".
     * @param  array<array-key, mixed>|null  $instructions  System instructions in GenAI content format, only when content capture is enabled.
     * @param  array<array-key, mixed>|null  $inputMessages  Input messages in GenAI content format, only when content capture is enabled.
     */
    public function __construct(
        public string $invocationId,
        public string $agentName,
        public string $agentClass,
        public string $providerName,
        public string $providerId,
        public string $providerDriver,
        public string $model,
        public bool $stream,
        public ?string $parentInvocationId = null,
        public ?string $parentToolInvocationId = null,
        public int $attachments = 0,
        public ?array $instructions = null,
        public ?array $inputMessages = null,
    ) {}
}
