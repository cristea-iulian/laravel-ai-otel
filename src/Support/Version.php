<?php

declare(strict_types=1);

namespace Literaj\AiOtel\Support;

use Composer\InstalledVersions;
use Throwable;

final class Version
{
    public const PACKAGE = 'literaj/laravel-ai-otel';

    public const INSTRUMENTATION_SCOPE = 'literaj/laravel-ai-otel';

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
