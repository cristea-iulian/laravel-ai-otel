<?php

declare(strict_types=1);

namespace Literaj\AiOtel;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Log\LogManager;
use InvalidArgumentException;
use Literaj\AiOtel\Contracts\Recorder;
use Literaj\AiOtel\Otel\TracerProviderResolver;
use Literaj\AiOtel\Recorders\LogRecorder;
use Literaj\AiOtel\Recorders\NullRecorder;
use Literaj\AiOtel\Recorders\SpanRecorder;
use Literaj\AiOtel\Support\Version;
use OpenTelemetry\API\Trace\TracerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the recorder for the configured driver.
 */
final class RecorderManager
{
    /** @var array<string, Closure(Container, array<string, mixed>): Recorder> */
    private array $customCreators = [];

    public function __construct(
        private readonly Container $app,
        private readonly Repository $config,
    ) {}

    public function driver(?string $name = null): Recorder
    {
        $name ??= $this->defaultDriver();

        if (isset($this->customCreators[$name])) {
            return ($this->customCreators[$name])($this->app, $this->driverConfig($name));
        }

        return match ($name) {
            'otlp', 'otel' => $this->createOtlpDriver(),
            'log' => $this->createLogDriver(),
            'null', 'none' => new NullRecorder,
            default => throw new InvalidArgumentException("Unsupported ai-otel driver [{$name}]."),
        };
    }

    /**
     * Register a custom recorder driver.
     *
     * @param  Closure(Container, array<string, mixed>): Recorder  $creator
     */
    public function extend(string $name, Closure $creator): self
    {
        $this->customCreators[$name] = $creator;

        return $this;
    }

    public function defaultDriver(): string
    {
        $driver = $this->config->get('ai-otel.driver', 'otlp');

        return is_string($driver) && $driver !== '' ? $driver : 'otlp';
    }

    public function tracer(): TracerInterface
    {
        $provider = (new TracerProviderResolver($this->app, $this->driverConfig('otlp')))->resolve();

        return $provider->getTracer(Version::INSTRUMENTATION_SCOPE, Version::get());
    }

    private function createOtlpDriver(): Recorder
    {
        return new SpanRecorder($this->tracer(), (bool) $this->config->get('ai-otel.activate_scopes', true));
    }

    private function createLogDriver(): Recorder
    {
        $config = $this->driverConfig('log');

        $channel = $config['channel'] ?? null;
        $level = $config['level'] ?? 'info';

        $log = $this->app->make(LogManager::class);

        $logger = $log instanceof LogManager
            ? $log->channel(is_string($channel) && $channel !== '' ? $channel : null)
            : $this->app->make(LoggerInterface::class);

        if (! $logger instanceof LoggerInterface) {
            throw new InvalidArgumentException('The ai-otel log driver could not resolve a PSR logger.');
        }

        return new LogRecorder($logger, is_string($level) && $level !== '' ? $level : 'info');
    }

    /**
     * @return array<string, mixed>
     */
    private function driverConfig(string $name): array
    {
        $config = $this->config->get("ai-otel.drivers.{$name}", []);

        $typed = [];

        if (is_array($config)) {
            foreach ($config as $key => $value) {
                if (is_string($key)) {
                    $typed[$key] = $value;
                }
            }
        }

        return $typed;
    }
}
