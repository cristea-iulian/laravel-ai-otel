<?php

declare(strict_types=1);

namespace Literaj\AiOtel\Recorders;

use Literaj\AiOtel\Attributes\GenAi;
use Literaj\AiOtel\Attributes\LaravelAi;
use Literaj\AiOtel\Contracts\Recorder;
use Literaj\AiOtel\Data\ApprovalRequested;
use Literaj\AiOtel\Data\ApprovalResolved;
use Literaj\AiOtel\Data\EmbeddingsGenerated;
use Literaj\AiOtel\Data\Failover;
use Literaj\AiOtel\Data\InvocationEnded;
use Literaj\AiOtel\Data\InvocationFailed;
use Literaj\AiOtel\Data\InvocationStarted;
use Literaj\AiOtel\Data\StepEnded;
use Literaj\AiOtel\Data\StepFailed;
use Literaj\AiOtel\Data\StepStarted;
use Literaj\AiOtel\Data\ToolEnded;
use Literaj\AiOtel\Data\ToolFailed;
use Literaj\AiOtel\Data\ToolStarted;
use Literaj\AiOtel\Support\Attributes;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use Throwable;

/**
 * Records GenAI spans through the OpenTelemetry API.
 *
 * Span tree for one agent run:
 *
 *   invoke_agent {agent}          INTERNAL
 *   ├── chat {model}              CLIENT   (one per step)
 *   ├── execute_tool {tool}       INTERNAL (one per tool call)
 *   │   └── invoke_agent {agent}  INTERNAL (a sub-agent used as a tool)
 *   └── chat {model}              CLIENT
 *
 *   embeddings {model}            CLIENT
 */
final class SpanRecorder implements Recorder
{
    /** @var array<string, InvocationState> */
    private array $invocations = [];

    /** @var array<string, SpanHandle> keyed by "{invocationId}:{stepNumber}" */
    private array $steps = [];

    /** @var array<string, SpanHandle> keyed by tool invocation id */
    private array $tools = [];

    public function __construct(
        private readonly TracerInterface $tracer,
        private readonly bool $activateScopes = true,
    ) {}

    public function invocationStarted(InvocationStarted $event): void
    {
        $state = $this->invocations[$event->invocationId] ?? null;

        // The SDK keeps one invocation id across failover attempts, so a second
        // start for a known invocation is a retry against another provider...
        if ($state !== null) {
            $state->attempt++;

            $attributes = Attributes::filter([
                LaravelAi::ATTEMPT => $state->attempt,
                GenAi::PROVIDER_NAME => $event->providerName,
                GenAi::REQUEST_MODEL => $event->model,
                LaravelAi::PROVIDER_NAME => $event->providerId,
                LaravelAi::PROVIDER_DRIVER => $event->providerDriver,
            ]);

            $state->handle->span->addEvent(LaravelAi::EVENT_RETRY, $attributes);
            $state->handle->span->setAttributes($attributes);

            return;
        }

        $parent = $this->parentContextForInvocation($event);

        $span = $this->tracer->spanBuilder(GenAi::OPERATION_INVOKE_AGENT.' '.$event->agentName)
            ->setParent($parent)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttributes(Attributes::filter([
                GenAi::OPERATION_NAME => GenAi::OPERATION_INVOKE_AGENT,
                GenAi::AGENT_NAME => $event->agentName,
                GenAi::PROVIDER_NAME => $event->providerName,
                GenAi::REQUEST_MODEL => $event->model,
                GenAi::REQUEST_STREAM => $event->stream,
                GenAi::SYSTEM_INSTRUCTIONS => Attributes::json($event->instructions),
                GenAi::INPUT_MESSAGES => Attributes::json($event->inputMessages),
                LaravelAi::INVOCATION_ID => $event->invocationId,
                LaravelAi::PARENT_INVOCATION_ID => $event->parentInvocationId,
                LaravelAi::PARENT_TOOL_INVOCATION_ID => $event->parentToolInvocationId,
                LaravelAi::AGENT_CLASS => $event->agentClass,
                LaravelAi::PROVIDER_NAME => $event->providerId,
                LaravelAi::PROVIDER_DRIVER => $event->providerDriver,
                LaravelAi::ATTACHMENTS => $event->attachments > 0 ? $event->attachments : null,
                LaravelAi::ATTEMPT => 1,
            ]))
            ->startSpan();

        $this->invocations[$event->invocationId] = new InvocationState($this->handle($span, $parent), $event->stream);
    }

    public function invocationEnded(InvocationEnded $event): void
    {
        $state = $this->takeInvocation($event->invocationId);

        if ($state === null) {
            return;
        }

        $this->endLingering($state);

        $span = $state->handle->span;

        $span->setAttributes(Attributes::filter([
            ...Attributes::usage($event->usage),
            GenAi::CONVERSATION_ID => $event->conversationId,
            GenAi::RESPONSE_MODEL => $event->responseModel,
            GenAi::RESPONSE_FINISH_REASONS => $state->lastFinishReason !== null ? [$state->lastFinishReason] : null,
            GenAi::OUTPUT_TYPE => $event->structured ? GenAi::OUTPUT_TYPE_JSON : null,
            GenAi::OUTPUT_MESSAGES => Attributes::json($event->outputMessages),
            LaravelAi::STRUCTURED => $event->structured ? true : null,
            LaravelAi::PENDING_APPROVALS => $event->pendingApprovals > 0 ? $event->pendingApprovals : null,
        ]));

        if ($event->pendingApprovals > 0) {
            $span->addEvent(LaravelAi::EVENT_APPROVAL_REQUESTED, [LaravelAi::PENDING_APPROVALS => $event->pendingApprovals]);
        }

        $state->handle->end();
    }

    public function invocationFailed(InvocationFailed $event): void
    {
        $state = $this->takeInvocation($event->invocationId);

        if ($state === null) {
            return;
        }

        $this->endLingering($state);

        $this->fail($state->handle->span, $event->exception);

        $state->handle->span->setAttribute(GenAi::RESPONSE_FINISH_REASONS, [GenAi::FINISH_REASON_ERROR]);

        $state->handle->end();
    }

    public function stepStarted(StepStarted $event): void
    {
        $state = $this->invocations[$event->invocationId] ?? null;

        $parent = $state?->handle->context ?? Context::getCurrent();

        $span = $this->tracer->spanBuilder(GenAi::OPERATION_CHAT.' '.$event->model)
            ->setParent($parent)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttributes(Attributes::filter([
                GenAi::OPERATION_NAME => GenAi::OPERATION_CHAT,
                GenAi::PROVIDER_NAME => $event->providerName,
                GenAi::REQUEST_MODEL => $event->model,
                GenAi::REQUEST_STREAM => $event->stream,
                GenAi::REQUEST_MAX_TOKENS => $event->maxTokens,
                GenAi::REQUEST_TEMPERATURE => $event->temperature,
                GenAi::REQUEST_TOP_P => $event->topP,
                GenAi::SYSTEM_INSTRUCTIONS => Attributes::json($event->instructions),
                GenAi::INPUT_MESSAGES => Attributes::json($event->inputMessages),
                LaravelAi::INVOCATION_ID => $event->invocationId,
                LaravelAi::STEP_NUMBER => $event->stepNumber,
                LaravelAi::STEP_FINAL => $event->isFinalStep ? true : null,
                LaravelAi::PROVIDER_NAME => $event->providerId,
                LaravelAi::PROVIDER_DRIVER => $event->providerDriver,
            ]))
            ->startSpan();

        $key = self::stepKey($event->invocationId, $event->stepNumber);

        $this->steps[$key] = $this->handle($span, $parent);

        if ($state !== null) {
            $state->stepKeys[] = $key;
        }
    }

    public function stepEnded(StepEnded $event): void
    {
        $handle = $this->takeStep($event->invocationId, $event->stepNumber);

        if ($state = $this->invocations[$event->invocationId] ?? null) {
            $state->lastFinishReason = $event->finishReason;
        }

        if ($handle === null) {
            return;
        }

        $handle->span->setAttributes(Attributes::filter([
            ...Attributes::usage($event->usage),
            GenAi::RESPONSE_MODEL => $event->responseModel,
            GenAi::RESPONSE_FINISH_REASONS => [$event->finishReason],
            GenAi::OUTPUT_TYPE => $event->structured ? GenAi::OUTPUT_TYPE_JSON : null,
            GenAi::OUTPUT_MESSAGES => Attributes::json($event->outputMessages),
            LaravelAi::STEP_TOOL_CALLS => $event->toolCalls !== [] ? count($event->toolCalls) : null,
        ]));

        $handle->end();
    }

    public function stepFailed(StepFailed $event): void
    {
        $handle = $this->takeStep($event->invocationId, $event->stepNumber);

        if ($handle === null) {
            return;
        }

        $this->fail($handle->span, $event->exception);

        $handle->span->setAttribute(GenAi::RESPONSE_FINISH_REASONS, [GenAi::FINISH_REASON_ERROR]);

        $handle->end();
    }

    public function toolStarted(ToolStarted $event): void
    {
        $state = $this->invocations[$event->invocationId] ?? null;

        $parent = $state?->handle->context ?? Context::getCurrent();

        $span = $this->tracer->spanBuilder(GenAi::OPERATION_EXECUTE_TOOL.' '.$event->toolName)
            ->setParent($parent)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttributes(Attributes::filter([
                GenAi::OPERATION_NAME => GenAi::OPERATION_EXECUTE_TOOL,
                GenAi::TOOL_NAME => $event->toolName,
                GenAi::TOOL_TYPE => GenAi::TOOL_TYPE_FUNCTION,
                GenAi::TOOL_CALL_ID => $event->toolCallId,
                GenAi::TOOL_DESCRIPTION => $event->description,
                GenAi::TOOL_CALL_ARGUMENTS => Attributes::json($event->arguments),
                GenAi::AGENT_NAME => $event->agentName,
                LaravelAi::INVOCATION_ID => $event->invocationId,
                LaravelAi::TOOL_INVOCATION_ID => $event->toolInvocationId,
                LaravelAi::TOOL_CLASS => $event->toolClass,
            ]))
            ->startSpan();

        $this->tools[$event->toolInvocationId] = $this->handle($span, $parent);

        if ($state !== null) {
            $state->toolKeys[] = $event->toolInvocationId;
        }
    }

    public function toolEnded(ToolEnded $event): void
    {
        $handle = $this->takeTool($event->toolInvocationId);

        if ($handle === null) {
            return;
        }

        $handle->span->setAttributes(Attributes::filter([
            GenAi::TOOL_CALL_RESULT => $event->result,
        ]));

        $handle->end();
    }

    public function toolFailed(ToolFailed $event): void
    {
        $handle = $this->takeTool($event->toolInvocationId);

        if ($handle === null) {
            return;
        }

        $this->fail($handle->span, $event->exception);

        $handle->end();
    }

    public function embeddingsGenerated(EmbeddingsGenerated $event): void
    {
        // Embeddings have no failure event in the SDK, so the span is created
        // once the call has completed, with the recorded start time...
        $this->tracer->spanBuilder(GenAi::OPERATION_EMBEDDINGS.' '.$event->model)
            ->setParent(Context::getCurrent())
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setStartTimestamp($event->startNanos)
            ->setAttributes(Attributes::filter([
                GenAi::OPERATION_NAME => GenAi::OPERATION_EMBEDDINGS,
                GenAi::PROVIDER_NAME => $event->providerName,
                GenAi::REQUEST_MODEL => $event->model,
                GenAi::RESPONSE_MODEL => $event->responseModel,
                GenAi::EMBEDDINGS_DIMENSION_COUNT => $event->dimensions > 0 ? $event->dimensions : null,
                GenAi::USAGE_INPUT_TOKENS => $event->inputTokens,
                LaravelAi::INVOCATION_ID => $event->invocationId,
                LaravelAi::EMBEDDINGS_INPUT_COUNT => $event->inputCount,
                LaravelAi::PROVIDER_NAME => $event->providerId,
                LaravelAi::PROVIDER_DRIVER => $event->providerDriver,
            ]))
            ->startSpan()
            ->end($event->endNanos);
    }

    public function failedOver(Failover $event): void
    {
        $state = $this->invocations[$event->invocationId] ?? null;

        $state?->handle->span->addEvent(LaravelAi::EVENT_FAILOVER, Attributes::filter([
            GenAi::PROVIDER_NAME => $event->providerName,
            GenAi::REQUEST_MODEL => $event->model,
            GenAi::ERROR_TYPE => $event->exception::class,
            'exception.message' => $event->exception->getMessage(),
            LaravelAi::PROVIDER_NAME => $event->providerId,
            LaravelAi::PROVIDER_DRIVER => $event->providerDriver,
        ]));
    }

    public function approvalRequested(ApprovalRequested $event): void
    {
        $state = $this->invocations[$event->invocationId] ?? null;

        $state?->handle->span->addEvent(LaravelAi::EVENT_APPROVAL_REQUESTED, Attributes::filter([
            LaravelAi::PENDING_APPROVALS => count($event->toolNames),
            GenAi::TOOL_NAME => $event->toolNames,
            GenAi::CONVERSATION_ID => $event->conversationId,
        ]));
    }

    public function approvalResolved(ApprovalResolved $event): void
    {
        $state = $this->invocations[$event->invocationId] ?? null;

        $state?->handle->span->addEvent(LaravelAi::EVENT_APPROVAL_RESOLVED, Attributes::filter([
            'resolved' => $event->resolved,
            'denied' => $event->denied,
            GenAi::CONVERSATION_ID => $event->conversationId,
        ]));
    }

    /**
     * End every span that is still open, marking it abandoned.
     *
     * Called when a request or queue job finishes so a stream that was never
     * consumed to its end cannot leak an active context into the next request
     * of a long-lived worker.
     */
    public function endAll(): void
    {
        foreach (array_reverse($this->invocations, true) as $invocationId => $state) {
            unset($this->invocations[$invocationId]);

            $this->endLingering($state);

            $state->handle->span->setAttribute(LaravelAi::ABANDONED, true);
            $state->handle->end();
        }

        foreach (array_reverse($this->tools, true) as $key => $handle) {
            unset($this->tools[$key]);

            $handle->span->setAttribute(LaravelAi::ABANDONED, true);
            $handle->end();
        }

        foreach (array_reverse($this->steps, true) as $key => $handle) {
            unset($this->steps[$key]);

            $handle->span->setAttribute(LaravelAi::ABANDONED, true);
            $handle->end();
        }
    }

    /**
     * A sub-agent prompted from inside a tool nests under that tool's span.
     */
    private function parentContextForInvocation(InvocationStarted $event): ContextInterface
    {
        if ($event->parentToolInvocationId !== null && isset($this->tools[$event->parentToolInvocationId])) {
            return $this->tools[$event->parentToolInvocationId]->context;
        }

        if ($event->parentInvocationId !== null && isset($this->invocations[$event->parentInvocationId])) {
            return $this->invocations[$event->parentInvocationId]->handle->context;
        }

        return Context::getCurrent();
    }

    private function handle(SpanInterface $span, ContextInterface $parent): SpanHandle
    {
        $context = $span->storeInContext($parent);

        return new SpanHandle($span, $context, $this->activateScopes ? $context->activate() : null);
    }

    private function fail(SpanInterface $span, Throwable $exception): void
    {
        $span->recordException($exception);
        $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        $span->setAttributes(Attributes::error($exception));
    }

    /**
     * Close step and tool spans that never received their end event, for
     * example when a stream was abandoned by its consumer.
     */
    private function endLingering(InvocationState $state): void
    {
        foreach (array_reverse($state->toolKeys) as $key) {
            if ($handle = $this->takeTool($key)) {
                $handle->span->setAttribute(LaravelAi::ABANDONED, true);
                $handle->end();
            }
        }

        foreach (array_reverse($state->stepKeys) as $key) {
            if ($handle = $this->steps[$key] ?? null) {
                unset($this->steps[$key]);
                $handle->span->setAttribute(LaravelAi::ABANDONED, true);
                $handle->span->setAttribute(GenAi::RESPONSE_FINISH_REASONS, [GenAi::FINISH_REASON_ERROR]);
                $handle->end();
            }
        }
    }

    private function takeInvocation(string $invocationId): ?InvocationState
    {
        $state = $this->invocations[$invocationId] ?? null;

        unset($this->invocations[$invocationId]);

        return $state;
    }

    private function takeStep(string $invocationId, int $stepNumber): ?SpanHandle
    {
        $key = self::stepKey($invocationId, $stepNumber);

        $handle = $this->steps[$key] ?? null;

        unset($this->steps[$key]);

        return $handle;
    }

    private function takeTool(string $toolInvocationId): ?SpanHandle
    {
        $handle = $this->tools[$toolInvocationId] ?? null;

        unset($this->tools[$toolInvocationId]);

        return $handle;
    }

    private static function stepKey(string $invocationId, int $stepNumber): string
    {
        return $invocationId.':'.$stepNumber;
    }
}
