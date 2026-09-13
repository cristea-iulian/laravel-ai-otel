<?php

declare(strict_types=1);

use CristeaIulian\AiOtel\Otel\TracerProviderResolver;
use CristeaIulian\AiOtel\RecorderManager;
use CristeaIulian\AiOtel\Recorders\NullRecorder;
use CristeaIulian\AiOtel\Recorders\SpanRecorder;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

afterEach(function (): void {
    Globals::reset();
});

test('a tracer provider bound in the container wins', function (): void {
    $bound = $this->app->make(TracerProviderInterface::class);

    $resolved = (new TracerProviderResolver($this->app, []))->resolve();

    expect($resolved)->toBe($bound);
});

test('a globally registered tracer provider is reused', function (): void {
    $this->app->forgetInstance(TracerProviderInterface::class);
    $this->app->offsetUnset(TracerProviderInterface::class);

    Globals::reset();

    $global = TracerProvider::builder()->addSpanProcessor(new SimpleSpanProcessor(new InMemoryExporter))->build();

    Globals::registerInitializer(fn ($configurator) => $configurator->withTracerProvider($global));

    $resolved = (new TracerProviderResolver($this->app, []))->resolve();

    expect($resolved)->toBe($global);
});

test('a tracer provider is bootstrapped from config when nothing is registered', function (): void {
    $this->app->forgetInstance(TracerProviderInterface::class);
    $this->app->offsetUnset(TracerProviderInterface::class);

    Globals::reset();

    $resolved = (new TracerProviderResolver($this->app, [
        'endpoint' => 'http://127.0.0.1:9',
        'protocol' => 'http/json',
        'headers' => 'x-api-key=abc,x-other=def',
        'processor' => 'simple',
        'service_name' => 'ai-otel-tests',
    ]))->resolve();

    expect($resolved)->toBeInstanceOf(TracerProvider::class)
        ->and($resolved->getSampler())->not->toBeNull();
});

test('an unsupported protocol is rejected with a helpful message', function (): void {
    $this->app->forgetInstance(TracerProviderInterface::class);
    $this->app->offsetUnset(TracerProviderInterface::class);

    Globals::reset();

    expect(fn () => (new TracerProviderResolver($this->app, ['protocol' => 'grpc']))->resolve())
        ->toThrow(InvalidArgumentException::class, 'Unsupported OTLP protocol [grpc]');
});

test('the manager builds the configured driver and supports custom drivers', function (): void {
    $manager = $this->app->make(RecorderManager::class);

    expect($manager->driver('otlp'))->toBeInstanceOf(SpanRecorder::class)
        ->and($manager->driver('null'))->toBeInstanceOf(NullRecorder::class);

    $manager->extend('custom', fn () => new NullRecorder);

    expect($manager->driver('custom'))->toBeInstanceOf(NullRecorder::class)
        ->and(fn () => $manager->driver('nope'))->toThrow(InvalidArgumentException::class);
});
