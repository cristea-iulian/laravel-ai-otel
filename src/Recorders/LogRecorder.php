<?php

declare(strict_types=1);

namespace CristeaIulian\AiOtel\Recorders;

use CristeaIulian\AiOtel\Attributes\GenAi;
use CristeaIulian\AiOtel\Attributes\LaravelAi;
use CristeaIulian\AiOtel\Contracts\Recorder;
use CristeaIulian\AiOtel\Data\ApprovalRequested;
use CristeaIulian\AiOtel\Data\ApprovalResolved;
use CristeaIulian\AiOtel\Data\EmbeddingsGenerated;
use CristeaIulian\AiOtel\Data\Failover;
use CristeaIulian\AiOtel\Data\InvocationEnded;
use CristeaIulian\AiOtel\Data\InvocationFailed;
use CristeaIulian\AiOtel\Data\InvocationStarted;
use CristeaIulian\AiOtel\Data\StepEnded;
use CristeaIulian\AiOtel\Data\StepFailed;
use CristeaIulian\AiOtel\Data\StepStarted;
use CristeaIulian\AiOtel\Data\ToolEnded;
use CristeaIulian\AiOtel\Data\ToolFailed;
use CristeaIulian\AiOtel\Data\ToolStarted;
use CristeaIulian\AiOtel\Support\Attributes;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes one structured log line per completed operation, using the same
 * attribute names as the span recorder. Needs no OpenTelemetry SDK.
 */
final class LogRecorder implements Recorder
{
    /** @var array<string, array{event: InvocationStarted, started: float, attempt: int}> */
    private array $invocations = [];

    /** @var array<string, StepStarted> */
    private array $steps = [];

    /** @var array<string, ToolStarted> */
    private array $tools = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $level = 'info',
    ) {}

    public function invocationStarted(InvocationStarted $event): void
    {
        if (isset($this->invocations[$event->invocationId])) {
            $this->invocations[$event->invocationId]['attempt']++;
            $this->invocations[$event->invocationId]['event'] = $event;

            return;
        }

        $this->invocations[$event->invocationId] = ['event' => $event, 'started' => hrtime(true), 'attempt' => 1];
    }

    public function invocationEnded(InvocationEnded $event): void
    {
        $entry = $this->invocations[$event->invocationId] ?? null;

        unset($this->invocations[$event->invocationId]);

        $this->log(GenAi::OPERATION_INVOKE_AGENT, [
            ...$this->invocationAttributes($entry),
            ...Attributes::usage($event->usage),
            GenAi::CONVERSATION_ID => $event->conversationId,
            GenAi::RESPONSE_MODEL => $event->responseModel,
            GenAi::OUTPUT_TYPE => $event->structured ? GenAi::OUTPUT_TYPE_JSON : null,
            GenAi::OUTPUT_MESSAGES => $event->outputMessages,
            LaravelAi::INVOCATION_ID => $event->invocationId,
            LaravelAi::PENDING_APPROVALS => $event->pendingApprovals > 0 ? $event->pendingApprovals : null,
            LaravelAi::DURATION_MS => $entry !== null ? self::elapsed($entry['started']) : null,
        ]);
    }

    public function invocationFailed(InvocationFailed $event): void
    {
        $entry = $this->invocations[$event->invocationId] ?? null;

        unset($this->invocations[$event->invocationId]);

        $this->log(GenAi::OPERATION_INVOKE_AGENT, [
            ...$this->invocationAttributes($entry),
            ...self::exception($event->exception),
            LaravelAi::INVOCATION_ID => $event->invocationId,
            LaravelAi::DURATION_MS => $entry !== null ? self::elapsed($entry['started']) : null,
        ], 'error');
    }

    public function stepStarted(StepStarted $event): void
    {
        $this->steps[self::stepKey($event->invocationId, $event->stepNumber)] = $event;
    }

    public function stepEnded(StepEnded $event): void
    {
        $started = $this->takeStep($event->invocationId, $event->stepNumber);

        $this->log(GenAi::OPERATION_CHAT, [
            ...$this->stepAttributes($started),
            ...Attributes::usage($event->usage),
            GenAi::RESPONSE_MODEL => $event->responseModel,
            GenAi::RESPONSE_FINISH_REASONS => [$event->finishReason],
            GenAi::OUTPUT_TYPE => $event->structured ? GenAi::OUTPUT_TYPE_JSON : null,
            GenAi::OUTPUT_MESSAGES => $event->outputMessages,
            LaravelAi::INVOCATION_ID => $event->invocationId,
            LaravelAi::STEP_NUMBER => $event->stepNumber,
            LaravelAi::STEP_TOOL_CALLS => $event->toolCalls !== [] ? count($event->toolCalls) : null,
            LaravelAi::DURATION_MS => $event->durationMs,
        ]);
    }

    public function stepFailed(StepFailed $event): void
    {
        $started = $this->takeStep($event->invocationId, $event->stepNumber);

        $this->log(GenAi::OPERATION_CHAT, [
            ...$this->stepAttributes($started),
            ...self::exception($event->exception),
            GenAi::RESPONSE_FINISH_REASONS => [GenAi::FINISH_REASON_ERROR],
            LaravelAi::INVOCATION_ID => $event->invocationId,
            LaravelAi::STEP_NUMBER => $event->stepNumber,
            LaravelAi::DURATION_MS => $event->durationMs,
        ], 'error');
    }

    public function toolStarted(ToolStarted $event): void
    {
        $this->tools[$event->toolInvocationId] = $event;
    }

    public function toolEnded(ToolEnded $event): void
    {
        $started = $this->takeTool($event->toolInvocationId);

        $this->log(GenAi::OPERATION_EXECUTE_TOOL, [
            ...$this->toolAttributes($started),
            GenAi::TOOL_CALL_RESULT => $event->result,
            LaravelAi::INVOCATION_ID => $event->invocationId,
            LaravelAi::TOOL_INVOCATION_ID => $event->toolInvocationId,
            LaravelAi::DURATION_MS => $event->durationMs,
        ]);
    }

    public function toolFailed(ToolFailed $event): void
    {
        $started = $this->takeTool($event->toolInvocationId);

        $this->log(GenAi::OPERATION_EXECUTE_TOOL, [
            ...$this->toolAttributes($started),
            ...self::exception($event->exception),
            LaravelAi::INVOCATION_ID => $event->invocationId,
            LaravelAi::TOOL_INVOCATION_ID => $event->toolInvocationId,
            LaravelAi::DURATION_MS => $event->durationMs,
        ], 'error');
    }

    public function embeddingsGenerated(EmbeddingsGenerated $event): void
    {
        $this->log(GenAi::OPERATION_EMBEDDINGS, [
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
            LaravelAi::DURATION_MS => ($event->endNanos - $event->startNanos) / 1_000_000,
        ]);
    }

    public function failedOver(Failover $event): void
    {
        $this->log(LaravelAi::EVENT_FAILOVER, [
            ...self::exception($event->exception),
            GenAi::PROVIDER_NAME => $event->providerName,
            GenAi::REQUEST_MODEL => $event->model,
            LaravelAi::INVOCATION_ID => $event->invocationId,
            LaravelAi::PROVIDER_NAME => $event->providerId,
            LaravelAi::PROVIDER_DRIVER => $event->providerDriver,
        ], 'warning');
    }

    public function approvalRequested(ApprovalRequested $event): void
    {
        $this->log(LaravelAi::EVENT_APPROVAL_REQUESTED, [
            GenAi::TOOL_NAME => $event->toolNames,
            GenAi::CONVERSATION_ID => $event->conversationId,
            LaravelAi::INVOCATION_ID => $event->invocationId,
            LaravelAi::PENDING_APPROVALS => count($event->toolNames),
        ]);
    }

    public function approvalResolved(ApprovalResolved $event): void
    {
        $this->log(LaravelAi::EVENT_APPROVAL_RESOLVED, [
            GenAi::CONVERSATION_ID => $event->conversationId,
            LaravelAi::INVOCATION_ID => $event->invocationId,
            'resolved' => $event->resolved,
            'denied' => $event->denied,
        ]);
    }

    /**
     * @param  array<non-empty-string, mixed>  $context
     */
    private function log(string $message, array $context, ?string $level = null): void
    {
        $this->logger->log($level ?? $this->level, $message, Attributes::filter($context));
    }

    /**
     * @param  array{event: InvocationStarted, started: float, attempt: int}|null  $entry
     * @return array<non-empty-string, mixed>
     */
    private function invocationAttributes(?array $entry): array
    {
        if ($entry === null) {
            return [GenAi::OPERATION_NAME => GenAi::OPERATION_INVOKE_AGENT];
        }

        $event = $entry['event'];

        return [
            GenAi::OPERATION_NAME => GenAi::OPERATION_INVOKE_AGENT,
            GenAi::AGENT_NAME => $event->agentName,
            GenAi::PROVIDER_NAME => $event->providerName,
            GenAi::REQUEST_MODEL => $event->model,
            GenAi::REQUEST_STREAM => $event->stream,
            GenAi::SYSTEM_INSTRUCTIONS => $event->instructions,
            GenAi::INPUT_MESSAGES => $event->inputMessages,
            LaravelAi::PARENT_INVOCATION_ID => $event->parentInvocationId,
            LaravelAi::PARENT_TOOL_INVOCATION_ID => $event->parentToolInvocationId,
            LaravelAi::AGENT_CLASS => $event->agentClass,
            LaravelAi::PROVIDER_NAME => $event->providerId,
            LaravelAi::PROVIDER_DRIVER => $event->providerDriver,
            LaravelAi::ATTEMPT => $entry['attempt'],
        ];
    }

    /**
     * @return array<non-empty-string, mixed>
     */
    private function stepAttributes(?StepStarted $event): array
    {
        if ($event === null) {
            return [GenAi::OPERATION_NAME => GenAi::OPERATION_CHAT];
        }

        return [
            GenAi::OPERATION_NAME => GenAi::OPERATION_CHAT,
            GenAi::PROVIDER_NAME => $event->providerName,
            GenAi::REQUEST_MODEL => $event->model,
            GenAi::REQUEST_STREAM => $event->stream,
            GenAi::REQUEST_MAX_TOKENS => $event->maxTokens,
            GenAi::REQUEST_TEMPERATURE => $event->temperature,
            GenAi::REQUEST_TOP_P => $event->topP,
            GenAi::SYSTEM_INSTRUCTIONS => $event->instructions,
            GenAi::INPUT_MESSAGES => $event->inputMessages,
            LaravelAi::STEP_FINAL => $event->isFinalStep ? true : null,
            LaravelAi::PROVIDER_NAME => $event->providerId,
            LaravelAi::PROVIDER_DRIVER => $event->providerDriver,
        ];
    }

    /**
     * @return array<non-empty-string, mixed>
     */
    private function toolAttributes(?ToolStarted $event): array
    {
        if ($event === null) {
            return [GenAi::OPERATION_NAME => GenAi::OPERATION_EXECUTE_TOOL];
        }

        return [
            GenAi::OPERATION_NAME => GenAi::OPERATION_EXECUTE_TOOL,
            GenAi::TOOL_NAME => $event->toolName,
            GenAi::TOOL_TYPE => GenAi::TOOL_TYPE_FUNCTION,
            GenAi::TOOL_CALL_ID => $event->toolCallId,
            GenAi::TOOL_DESCRIPTION => $event->description,
            GenAi::TOOL_CALL_ARGUMENTS => $event->arguments,
            GenAi::AGENT_NAME => $event->agentName,
            LaravelAi::TOOL_CLASS => $event->toolClass,
        ];
    }

    /**
     * @return array<non-empty-string, string>
     */
    private static function exception(Throwable $exception): array
    {
        return [
            ...Attributes::error($exception),
            'exception.message' => $exception->getMessage(),
        ];
    }

    private function takeStep(string $invocationId, int $stepNumber): ?StepStarted
    {
        $key = self::stepKey($invocationId, $stepNumber);

        $event = $this->steps[$key] ?? null;

        unset($this->steps[$key]);

        return $event;
    }

    private function takeTool(string $toolInvocationId): ?ToolStarted
    {
        $event = $this->tools[$toolInvocationId] ?? null;

        unset($this->tools[$toolInvocationId]);

        return $event;
    }

    private static function stepKey(string $invocationId, int $stepNumber): string
    {
        return $invocationId.':'.$stepNumber;
    }

    private static function elapsed(float $startedNanos): float
    {
        return round((hrtime(true) - $startedNanos) / 1_000_000, 3);
    }
}
