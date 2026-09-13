<?php

declare(strict_types=1);

namespace CristeaIulian\AiOtel;

use CristeaIulian\AiOtel\Contracts\Recorder;
use CristeaIulian\AiOtel\Contracts\Redactor;
use CristeaIulian\AiOtel\Laravel\AiEventListener;
use CristeaIulian\AiOtel\Recorders\SpanRecorder;
use CristeaIulian\AiOtel\Support\ContentCapture;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Events\ToolApprovalResolved;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Common\Time\ClockInterface;
use Psr\Log\LoggerInterface;

class AiOtelServiceProvider extends ServiceProvider
{
    /**
     * Laravel AI events handled by the agent loop instrumentation.
     *
     * @var array<class-string, string>
     */
    public const AGENT_EVENTS = [
        PromptingAgent::class => 'promptingAgent',
        StreamingAgent::class => 'promptingAgent',
        AgentPrompted::class => 'agentPrompted',
        AgentStreamed::class => 'agentPrompted',
        AgentFailed::class => 'agentFailed',
        AgentFailedOver::class => 'agentFailedOver',
        StartingStep::class => 'startingStep',
        StepCompleted::class => 'stepCompleted',
        StepFailed::class => 'stepFailed',
        InvokingTool::class => 'invokingTool',
        ToolInvoked::class => 'toolInvoked',
        ToolFailed::class => 'toolFailed',
        ToolApprovalRequested::class => 'toolApprovalRequested',
        ToolApprovalResolved::class => 'toolApprovalResolved',
    ];

    /**
     * @var array<class-string, string>
     */
    public const EMBEDDINGS_EVENTS = [
        GeneratingEmbeddings::class => 'generatingEmbeddings',
        EmbeddingsGenerated::class => 'embeddingsGenerated',
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-otel.php', 'ai-otel');

        $this->app->singleton(RecorderManager::class, fn (Container $app): RecorderManager => new RecorderManager($app, $app->make('config')));

        $this->app->singleton(Recorder::class, fn (Container $app): Recorder => $app->make(RecorderManager::class)->driver());

        $this->app->singleton(ContentCapture::class, function (Container $app): ContentCapture {
            $config = $app->make('config')->get('ai-otel.capture', []);
            $config = is_array($config) ? $config : [];

            $redactor = $config['redactor'] ?? null;

            if (is_string($redactor) && $redactor !== '') {
                $redactor = $app->make($redactor);
            }

            $maxLength = $config['max_length'] ?? 8192;

            return new ContentCapture(
                enabled: (bool) ($config['content'] ?? false),
                maxLength: is_numeric($maxLength) ? (int) $maxLength : 8192,
                redactor: $redactor instanceof Redactor ? $redactor : null,
            );
        });

        $this->app->bindIf(ClockInterface::class, static fn (): ClockInterface => Clock::getDefault());

        $this->app->singleton(AiEventListener::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/ai-otel.php' => $this->app->configPath('ai-otel.php'),
        ], 'ai-otel-config');

        if (! $this->app->make('config')->get('ai-otel.enabled', true)) {
            return;
        }

        $events = $this->app->make(Dispatcher::class);

        $listeners = [];

        if ($this->app->make('config')->get('ai-otel.operations.agents', true)) {
            $listeners += self::AGENT_EVENTS;
        }

        if ($this->app->make('config')->get('ai-otel.operations.embeddings', true)) {
            $listeners += self::EMBEDDINGS_EVENTS;
        }

        $missing = [];

        foreach ($listeners as $event => $method) {
            // The SDK is pre-1.0; if an event class disappears in a release
            // this package was not built against, say so instead of failing...
            if (! class_exists($event)) {
                $missing[] = $event;

                continue;
            }

            $events->listen($event, [AiEventListener::class, $method]);
        }

        $this->registerCleanupHooks();

        if ($missing !== [] && $this->app->bound(LoggerInterface::class)) {
            $this->app->make(LoggerInterface::class)->warning(
                'laravel-ai-otel: some Laravel AI events do not exist in the installed laravel/ai version and will not be traced.',
                ['events' => $missing],
            );
        }
    }

    /**
     * Close spans left open by an abandoned stream once the request or job
     * that started them is over, so nothing leaks into the next one.
     */
    private function registerCleanupHooks(): void
    {
        $cleanup = function (): void {
            if (! $this->app->resolved(Recorder::class)) {
                return;
            }

            $recorder = $this->app->make(Recorder::class);

            if ($recorder instanceof SpanRecorder) {
                $recorder->endAll();
            }
        };

        if ($this->app instanceof Application) {
            $this->app->terminating($cleanup);
        }

        $this->app->make(Dispatcher::class)->listen(JobProcessed::class, $cleanup);
    }
}
