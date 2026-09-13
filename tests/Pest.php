<?php

declare(strict_types=1);

use CristeaIulian\AiOtel\Tests\DisabledTestCase;
use CristeaIulian\AiOtel\Tests\TestCase;

pest()->extend(DisabledTestCase::class)->in('Disabled');
pest()->extend(TestCase::class)->in('Feature', 'Unit');
