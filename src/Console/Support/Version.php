<?php

namespace Facturas\Console\Support;

use Composer\InstalledVersions;

/** The published version of this package (`cli/version.ts`). */
final class Version
{
    public const PACKAGE = 'maurocasas/facturas-laravel';

    /** The version Composer installed, or `0.0.0` when Composer cannot tell. */
    public static function readCliVersion(): string
    {
        try {
            if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled(self::PACKAGE)) {
                $version = InstalledVersions::getPrettyVersion(self::PACKAGE);
                if (is_string($version) && $version !== '') {
                    return ltrim($version, 'v');
                }
            }
        } catch (\Throwable) {
            // Fall through to the placeholder, like a missing package.json.
        }

        return '0.0.0';
    }
}
