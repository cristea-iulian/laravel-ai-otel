<?php

declare(strict_types=1);

namespace CristeaIulian\AiOtel\Support;

use Composer\InstalledVersions;
use Throwable;

final class Version
{
    public const PACKAGE = 'cristea-iulian/laravel-ai-otel';

    public const INSTRUMENTATION_SCOPE = 'cristea-iulian/laravel-ai-otel';

    public static function get(): ?string
    {
        try {
            if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled(self::PACKAGE)) {
                return InstalledVersions::getPrettyVersion(self::PACKAGE);
            }
        } catch (Throwable) {
            // Fall through: the version is informational only.
        }

        return null;
    }
}
