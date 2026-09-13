<?php

declare(strict_types=1);

use Literaj\AiOtel\Tests\DisabledTestCase;
use Literaj\AiOtel\Tests\TestCase;

pest()->extend(DisabledTestCase::class)->in('Disabled');
pest()->extend(TestCase::class)->in('Feature', 'Unit');
