<?php

declare(strict_types=1);

namespace CristeaIulian\AiOtel\Recorders;

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

final class NullRecorder implements Recorder
{
    public function invocationStarted(InvocationStarted $event): void {}

    public function invocationEnded(InvocationEnded $event): void {}

    public function invocationFailed(InvocationFailed $event): void {}

    public function stepStarted(StepStarted $event): void {}

    public function stepEnded(StepEnded $event): void {}

    public function stepFailed(StepFailed $event): void {}

    public function toolStarted(ToolStarted $event): void {}

    public function toolEnded(ToolEnded $event): void {}

    public function toolFailed(ToolFailed $event): void {}

    public function embeddingsGenerated(EmbeddingsGenerated $event): void {}

    public function failedOver(Failover $event): void {}

    public function approvalRequested(ApprovalRequested $event): void {}

    public function approvalResolved(ApprovalResolved $event): void {}
}
