<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

/**
 * Scans the composer autoload paths plus the PHP files the application loads
 * outside them: the set Rector covers and the HTTP entry point. Unused packages
 * are reported for require only (the tool's default): the dev tools run as
 * binaries and analyser extensions, which no scanned file references.
 *
 * Ignored, each on the narrowest path: monolog/monolog in the framework's own
 * config/logging.php (it arrives with laravel/framework); symfony/http-foundation
 * and symfony/http-kernel in the Notifications module the task provides, because
 * `validate --strict` rejects a `*` constraint, so declaring them would copy
 * laravel/framework's Symfony range and have to follow it on every upgrade;
 * laravel/tinker, which only `artisan tinker` loads.
 */
return (new Configuration)
    ->addPathsToScan([
        __DIR__.'/bootstrap/app.php',
        __DIR__.'/bootstrap/providers.php',
        __DIR__.'/config',
        __DIR__.'/database/migrations',
        __DIR__.'/public/index.php',
        __DIR__.'/routes',
    ], isDev: false)
    ->ignoreErrorsOnPackageAndPath('monolog/monolog', __DIR__.'/config/logging.php', [ErrorType::SHADOW_DEPENDENCY])
    ->ignoreErrorsOnPackagesAndPaths(
        ['symfony/http-foundation', 'symfony/http-kernel'],
        [__DIR__.'/src/Modules/Notifications'],
        [ErrorType::SHADOW_DEPENDENCY],
    )
    ->ignoreErrorsOnPackage('laravel/tinker', [ErrorType::UNUSED_DEPENDENCY]);
