<?php

declare(strict_types=1);

namespace CristeaIulian\AiOtel\Tests;

abstract class DisabledTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('ai-otel.enabled', false);
    }
}
