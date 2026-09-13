<?php

declare(strict_types=1);

namespace CristeaIulian\AiOtel\Attributes;

/**
 * Attributes specific to the Laravel AI SDK that have no GenAI equivalent.
 */
final class LaravelAi
{
    public const INVOCATION_ID = 'laravel.ai.invocation.id';

    public const PARENT_INVOCATION_ID = 'laravel.ai.invocation.parent_id';

    public const ATTEMPT = 'laravel.ai.invocation.attempt';

    public const TOOL_INVOCATION_ID = 'laravel.ai.tool_invocation.id';

    public const PARENT_TOOL_INVOCATION_ID = 'laravel.ai.tool_invocation.parent_id';

    public const AGENT_CLASS = 'laravel.ai.agent.class';

    public const PROVIDER_NAME = 'laravel.ai.provider.name';

    public const PROVIDER_DRIVER = 'laravel.ai.provider.driver';

    public const STEP_NUMBER = 'laravel.ai.step.number';

    public const STEP_FINAL = 'laravel.ai.step.final';

    public const STEP_TOOL_CALLS = 'laravel.ai.step.tool_calls';

    public const TOOL_CLASS = 'laravel.ai.tool.class';

    public const ATTACHMENTS = 'laravel.ai.attachments';

    public const STRUCTURED = 'laravel.ai.structured';

    public const PENDING_APPROVALS = 'laravel.ai.pending_approvals';

    public const EMBEDDINGS_INPUT_COUNT = 'laravel.ai.embeddings.input_count';

    public const ABANDONED = 'laravel.ai.abandoned';

    public const DURATION_MS = 'laravel.ai.duration_ms';

    public const EVENT_RETRY = 'laravel.ai.retry';

    public const EVENT_FAILOVER = 'laravel.ai.failover';

    public const EVENT_APPROVAL_REQUESTED = 'laravel.ai.tool_approval.requested';

    public const EVENT_APPROVAL_RESOLVED = 'laravel.ai.tool_approval.resolved';
}
