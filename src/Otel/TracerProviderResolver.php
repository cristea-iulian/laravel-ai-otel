<?php

declare(strict_types=1);

namespace CristeaIulian\AiOtel\Otel;

use CristeaIulian\AiOtel\Exceptions\MissingOpenTelemetrySdkException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobProcessed;
use InvalidArgumentException;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface as SdkTracerProviderInterface;

/**
 * Finds the TracerProvider the otlp driver should record through.
 *
 * Resolution order:
 *  1. A TracerProviderInterface bound in the container (tests, custom setups).
 *  2. The globally registered provider (open-telemetry/opentelemetry-auto-laravel
 *     or any SDK autoloader), so agent spans nest under request and job spans.
 *  3. A provider bootstrapped from config/ai-otel.php when the SDK and the OTLP
 *     exporter are installed.
 *  4. A clear exception telling you which package to install.
 */
final class TracerProviderResolver
{
    /**
     * @param  array<string, mixed>  $config  The "drivers.otlp" section of config/ai-otel.php.
     */
    public function __construct(
        private readonly Container $app,
        private readonly array $config,
    ) {}

    public function resolve(): TracerProviderInterface
    {
        if ($this->app->bound(TracerProviderInterface::class)) {
            $bound = $this->app->make(TracerProviderInterface::class);

            if ($bound instanceof TracerProviderInterface) {
                return $bound;
            }
        }

        $global = Globals::tracerProvider();

        if (! $global instanceof NoopTracerProvider) {
            return $global;
        }

        if (! class_exists(TracerProvider::class) || ! class_exists(SpanExporter::class)) {
            throw MissingOpenTelemetrySdkException::forOtlpDriver();
        }

        return $this->bootstrap();
    }

    private function bootstrap(): TracerProviderInterface
    {
        $protocol = $this->string('protocol', 'http/protobuf');

        $contentType = match ($protocol) {
            'http/protobuf' => ContentTypes::PROTOBUF,
            'http/json' => ContentTypes::JSON,
            default => throw new InvalidArgumentException(
                "Unsupported OTLP protocol [{$protocol}] for the ai-otel bootstrap. Use http/protobuf or http/json, "
                .'or register a TracerProvider yourself (for example with open-telemetry/opentelemetry-auto-laravel).'
            ),
        };

        $timeout = $this->config['timeout'] ?? 10;

        $transport = (new OtlpHttpTransportFactory)->create(
            $this->tracesEndpoint(),
            $contentType,
            $this->headers(),
            null,
            is_numeric($timeout) ? (float) $timeout : 10.0,
        );

        $exporter = new SpanExporter($transport);

        $processor = $this->string('processor', 'batch') === 'simple'
            ? new SimpleSpanProcessor($exporter)
            : BatchSpanProcessor::builder($exporter)->build();

        $resourceAttributes = $this->config['resource'] ?? [];

        $resource = ResourceInfoFactory::defaultResource()->merge(ResourceInfo::create(Attributes::create([
            'service.name' => $this->string('service_name', 'laravel'),
            ...(is_array($resourceAttributes) ? array_filter($resourceAttributes, static fn ($v): bool => $v !== null) : []),
        ])));

        $provider = TracerProvider::builder()
            ->addSpanProcessor($processor)
            ->setResource($resource)
            ->build();

        $this->registerFlushHooks($provider);

        return $provider;
    }

    /**
     * The batch processor only exports when spans end, so flush at the points
     * where a PHP process is about to go idle: after each request (also under
     * Octane), after each queue job, and at shutdown.
     */
    private function registerFlushHooks(SdkTracerProviderInterface $provider): void
    {
        if ($this->app instanceof Application) {
            $this->app->terminating(static function () use ($provider): void {
                $provider->forceFlush();
            });
        }

        if ($this->app->bound(Dispatcher::class)) {
            $events = $this->app->make(Dispatcher::class);

            if ($events instanceof Dispatcher) {
                $events->listen(JobProcessed::class, static function () use ($provider): void {
                    $provider->forceFlush();
                });
            }
        }

        register_shutdown_function(static function () use ($provider): void {
            $provider->shutdown();
        });
    }

    private function tracesEndpoint(): string
    {
        $endpoint = rtrim($this->string('endpoint', 'http://localhost:4318'), '/');

        return str_ends_with($endpoint, '/v1/traces') ? $endpoint : $endpoint.'/v1/traces';
    }

    /**
     * Parse "key=value,key2=value2" as used by OTEL_EXPORTER_OTLP_HEADERS.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        $raw = $this->config['headers'] ?? '';

        $headers = [];

        if (is_array($raw)) {
            foreach ($raw as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $headers[$key] = (string) $value;
                }
            }

            return $headers;
        }

        if (! is_string($raw)) {
            return $headers;
        }

        foreach (explode(',', $raw) as $pair) {
            if (! str_contains($pair, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $pair, 2);

            $headers[trim($key)] = trim(rawurldecode($value));
        }

        return $headers;
    }

    private function string(string $key, string $default): string
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
