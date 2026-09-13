<?php

declare(strict_types=1);

namespace Literaj\AiOtel\Laravel;

use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\EmbeddingsGenerated as SdkEmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed as SdkStepFailed;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Events\ToolApprovalResolved;
use Laravel\Ai\Events\ToolFailed as SdkToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Literaj\AiOtel\Attributes\GenAi;
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
use Literaj\AiOtel\Support\ContentCapture;
use OpenTelemetry\API\Common\Time\ClockInterface;

/**
 * Translates Laravel AI SDK events into recorder calls.
 *
 * This is the only class that knows about the SDK's event payloads. It keeps
 * a little per-invocation state the recorders cannot derive on their own.
 */
final class AiEventListener
{
    /** @var array<string, bool> invocation id => streaming? */
    private array $streams = [];

    /** @var array<string, array<string, string>> invocation id => [tool name => tool call id] */
    private array $toolCallIds = [];

    /** @var array<string, int> embeddings invocation id => start epoch nanos */
    private array $embeddingStarts = [];

    public function __construct(
        private readonly Recorder $recorder,
        private readonly ContentCapture $capture,
        private readonly ClockInterface $clock,
    ) {}

    public function promptingAgent(PromptingAgent $event): void
    {
        $prompt = $event->prompt;
        $agent = $prompt->agent;
        $provider = $prompt->provider();
        $stream = $event instanceof StreamingAgent;

        $this->streams[$event->invocationId] = $stream;

        $this->recorder->invocationStarted(new InvocationStarted(
            invocationId: $event->invocationId,
            agentName: Names::agent($agent),
            agentClass: $agent::class,
            providerName: ProviderNames::semantic($provider->driver()),
            providerId: $provider->name(),
            providerDriver: $provider->driver(),
            model: $prompt->model,
            stream: $stream,
            parentInvocationId: $prompt->parentInvocationId,
            parentToolInvocationId: $prompt->parentToolInvocationId,
            attachments: $prompt->attachments->count(),
            instructions: $this->instructions($agent),
            inputMessages: $this->capture->enabled()
                ? $this->capture->structure(MessageSerializer::userPrompt($prompt->prompt), GenAi::INPUT_MESSAGES)
                : null,
        ));
    }

    public function agentPrompted(AgentPrompted $event): void
    {
        $response = $event->response;

        $this->recorder->invocationEnded(new InvocationEnded(
            invocationId: $event->invocationId,
            usage: UsageMapper::from($response->usage),
            conversationId: $response->conversationId,
            responseModel: $response->meta->model,
            responseProvider: $response->meta->provider,
            structured: $response instanceof StructuredAgentResponse,
            toolCalls: $response->toolCalls->count(),
            pendingApprovals: $response->pendingApprovals->count(),
            outputMessages: $this->capture->enabled()
                ? $this->capture->structure(MessageSerializer::output($response->text), GenAi::OUTPUT_MESSAGES)
                : null,
        ));

        $this->forget($event->invocationId);
    }

    public function agentFailed(AgentFailed $event): void
    {
        $this->recorder->invocationFailed(new InvocationFailed($event->invocationId, $event->exception));

        $this->forget($event->invocationId);
    }

    public function agentFailedOver(AgentFailedOver $event): void
    {
        $this->recorder->failedOver(new Failover(
            invocationId: $event->invocationId,
            providerName: ProviderNames::semantic($event->provider->driver()),
            providerId: $event->provider->name(),
            providerDriver: $event->provider->driver(),
            model: $event->model,
            exception: $event->exception,
        ));
    }

    public function startingStep(StartingStep $event): void
    {
        $options = $event->options;

        $this->recorder->stepStarted(new StepStarted(
            invocationId: $event->invocationId,
            stepNumber: $event->stepNumber,
            isFinalStep: $event->isFinalStep,
            providerName: ProviderNames::semantic($event->provider->driver()),
            providerId: $event->provider->name(),
            providerDriver: $event->provider->driver(),
            model: $event->model,
            stream: $this->streams[$event->invocationId] ?? false,
            maxTokens: $options?->maxTokens,
            temperature: $options?->temperature,
            topP: $options?->topP,
            instructions: $this->instructions($event->agent),
            inputMessages: $this->capture->enabled()
                ? $this->capture->structure(MessageSerializer::messages($event->messages), GenAi::INPUT_MESSAGES)
                : null,
        ));
    }

    public function stepCompleted(StepCompleted $event): void
    {
        $response = $event->response;

        $toolCalls = [];

        foreach ($response->toolCalls as $toolCall) {
            if ($toolCall instanceof ToolCall) {
                $toolCalls[] = ['id' => $toolCall->id, 'name' => $toolCall->name];
            }
        }

        $this->rememberToolCallIds($event->invocationId, $toolCalls);

        $this->recorder->stepEnded(new StepEnded(
            invocationId: $event->invocationId,
            stepNumber: $event->stepNumber,
            finishReason: $response->finishReason->value,
            usage: UsageMapper::from($response->usage),
            durationMs: $event->time,
            toolCalls: $toolCalls,
            responseModel: $response->meta->model,
            responseProvider: $response->meta->provider,
            structured: $response->structured !== null,
            outputMessages: $this->capture->enabled()
                ? $this->capture->structure(
                    MessageSerializer::output($response->text, $response->toolCalls, $response->finishReason->value),
                    GenAi::OUTPUT_MESSAGES,
                )
                : null,
        ));
    }

    public function stepFailed(SdkStepFailed $event): void
    {
        $this->recorder->stepFailed(new StepFailed(
            invocationId: $event->invocationId,
            stepNumber: $event->stepNumber,
            exception: $event->exception,
            durationMs: $event->time,
        ));
    }

    public function invokingTool(InvokingTool $event): void
    {
        $tool = $event->tool;
        $name = Names::tool($tool);

        $this->recorder->toolStarted(new ToolStarted(
            invocationId: $event->invocationId,
            toolInvocationId: $event->toolInvocationId,
            toolName: $name,
            toolClass: $tool::class,
            agentName: Names::agent($event->agent),
            toolCallId: $this->toolCallIds[$event->invocationId][$name] ?? null,
            description: $this->capture->enabled()
                ? $this->capture->text((string) $tool->description(), GenAi::TOOL_DESCRIPTION)
                : null,
            arguments: $this->capture->enabled()
                ? $this->capture->structure($event->arguments, GenAi::TOOL_CALL_ARGUMENTS)
                : null,
        ));
    }

    public function toolInvoked(ToolInvoked $event): void
    {
        $this->recorder->toolEnded(new ToolEnded(
            invocationId: $event->invocationId,
            toolInvocationId: $event->toolInvocationId,
            durationMs: $event->time,
            result: $this->capture->scalar($event->result, GenAi::TOOL_CALL_RESULT),
        ));
    }

    public function toolFailed(SdkToolFailed $event): void
    {
        $this->recorder->toolFailed(new ToolFailed(
            invocationId: $event->invocationId,
            toolInvocationId: $event->toolInvocationId,
            exception: $event->exception,
            durationMs: $event->time,
        ));
    }

    public function toolApprovalRequested(ToolApprovalRequested $event): void
    {
        $names = [];

        foreach ($event->pendingApprovals as $approval) {
            if ($approval instanceof PendingApproval) {
                $names[] = $approval->tool;
            }
        }

        $this->recorder->approvalRequested(new ApprovalRequested(
            invocationId: $event->invocationId,
            toolNames: $names,
            conversationId: $event->conversationId,
        ));
    }

    public function toolApprovalResolved(ToolApprovalResolved $event): void
    {
        $denied = 0;

        foreach ($event->toolResults as $result) {
            if ($result instanceof ToolResult && $result->denied) {
                $denied++;
            }
        }

        $this->recorder->approvalResolved(new ApprovalResolved(
            invocationId: $event->invocationId,
            resolved: $event->toolResults->count(),
            denied: $denied,
            conversationId: $event->conversationId,
        ));
    }

    public function generatingEmbeddings(GeneratingEmbeddings $event): void
    {
        $this->embeddingStarts[$event->invocationId] = $this->clock->now();
    }

    public function embeddingsGenerated(SdkEmbeddingsGenerated $event): void
    {
        $now = $this->clock->now();

        $start = $this->embeddingStarts[$event->invocationId] ?? $now;

        unset($this->embeddingStarts[$event->invocationId]);

        $this->recorder->embeddingsGenerated(new EmbeddingsGenerated(
            invocationId: $event->invocationId,
            providerName: ProviderNames::semantic($event->provider->driver()),
            providerId: $event->provider->name(),
            providerDriver: $event->provider->driver(),
            model: $event->model,
            dimensions: $event->prompt->dimensions,
            inputCount: $event->prompt->count(),
            inputTokens: $event->response->tokens,
            startNanos: $start,
            endNanos: $now,
            responseModel: $event->response->meta->model,
        ));
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function instructions(Agent $agent): ?array
    {
        if (! $this->capture->enabled()) {
            return null;
        }

        $instructions = (string) $agent->instructions();

        return $instructions === ''
            ? null
            : $this->capture->structure(MessageSerializer::instructions($instructions), GenAi::SYSTEM_INSTRUCTIONS);
    }

    /**
     * Tool events carry no tool call id, so remember the ids the last step
     * produced and match them by tool name when the name is unambiguous.
     *
     * @param  list<array{id: string, name: string}>  $toolCalls
     */
    private function rememberToolCallIds(string $invocationId, array $toolCalls): void
    {
        $ids = [];

        foreach ($toolCalls as $toolCall) {
            $ids[$toolCall['name']] = array_key_exists($toolCall['name'], $ids) ? null : $toolCall['id'];
        }

        $this->toolCallIds[$invocationId] = array_filter($ids, static fn (?string $id): bool => $id !== null);
    }

    private function forget(string $invocationId): void
    {
        unset($this->streams[$invocationId], $this->toolCallIds[$invocationId]);
    }
}
