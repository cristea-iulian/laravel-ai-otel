<?php

declare(strict_types=1);

namespace Literaj\AiOtel\Contracts;

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

/**
 * A sink for SDK-agnostic GenAI telemetry.
 *
 * Adapters (the Laravel AI listener today) translate framework events into
 * these calls. Recorders turn them into spans, log lines, or nothing.
 */
interface Recorder
{
    public function invocationStarted(InvocationStarted $event): void;

    public function invocationEnded(InvocationEnded $event): void;

    public function invocationFailed(InvocationFailed $event): void;

    public function stepStarted(StepStarted $event): void;

    public function stepEnded(StepEnded $event): void;

    public function stepFailed(StepFailed $event): void;

    public function toolStarted(ToolStarted $event): void;

    public function toolEnded(ToolEnded $event): void;

    public function toolFailed(ToolFailed $event): void;

    public function embeddingsGenerated(EmbeddingsGenerated $event): void;

    public function failedOver(Failover $event): void;

    public function approvalRequested(ApprovalRequested $event): void;

    public function approvalResolved(ApprovalResolved $event): void;
}
