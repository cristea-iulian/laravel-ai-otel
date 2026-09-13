<?php

declare(strict_types=1);

namespace Literaj\AiOtel\Tests;

use Illuminate\Support\Collection;
use Laravel\Ai\AiServiceProvider;
use Literaj\AiOtel\AiOtelServiceProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use PHPUnit\Framework\Assert;

abstract class TestCase extends OrchestraTestCase
{
    protected InMemoryExporter $exporter;

    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            AiOtelServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $this->exporter = new InMemoryExporter;

        $app->instance(TracerProviderInterface::class, TracerProvider::builder()
            ->addSpanProcessor(new SimpleSpanProcessor($this->exporter))
            ->build());

        $app['config']->set('ai-otel.driver', 'otlp');
        $app['config']->set('ai.default', 'openai');
        $app['config']->set('ai.providers.openai', ['driver' => 'openai', 'key' => 'test-key']);
    }

    /**
     * @return Collection<int, ImmutableSpan>
     */
    protected function spans(): Collection
    {
        /** @var Collection<int, ImmutableSpan> $spans */
        $spans = Collection::make($this->exporter->getSpans());

        return $spans;
    }

    protected function span(string $name): ImmutableSpan
    {
        $span = $this->spans()->first(fn (ImmutableSpan $span): bool => $span->getName() === $name);

        Assert::assertNotNull($span, sprintf('No span named [%s] was recorded. Recorded: %s', $name, $this->spans()->map->getName()->implode(', ')));

        return $span;
    }

    /**
     * @return Collection<int, ImmutableSpan>
     */
    protected function spansNamed(string $prefix): Collection
    {
        return $this->spans()->filter(fn (ImmutableSpan $span): bool => str_starts_with($span->getName(), $prefix))->values();
    }

    /**
     * @return array<string, mixed>
     */
    protected function attributes(ImmutableSpan $span): array
    {
        return $span->getAttributes()->toArray();
    }
}
