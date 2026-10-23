<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

/**
 * Scans the composer autoload paths plus the PHP files the application loads
 * outside them, the same set Rector covers.
 *
 * Ignored, each on the narrowest path: monolog/monolog and ext-pdo_mysql in the
 * framework's own config files (monolog arrives with laravel/framework, the
 * pdo_mysql constants sit behind extension_loaded()); symfony/http-foundation
 * and symfony/http-kernel in the Notifications module, which the task provides
 * and which stays unchanged; laravel/tinker, which only `artisan tinker` loads.
 */
return (new Configuration)
    ->addPathsToScan([
        __DIR__.'/bootstrap/app.php',
        __DIR__.'/bootstrap/providers.php',
        __DIR__.'/config',
        __DIR__.'/database/migrations',
        __DIR__.'/routes',
    ], isDev: false)
    ->ignoreErrorsOnPackageAndPath('monolog/monolog', __DIR__.'/config/logging.php', [ErrorType::SHADOW_DEPENDENCY])
    ->ignoreErrorsOnExtensionAndPath('ext-pdo_mysql', __DIR__.'/config/database.php', [ErrorType::SHADOW_DEPENDENCY])
    ->ignoreErrorsOnPackagesAndPaths(
        ['symfony/http-foundation', 'symfony/http-kernel'],
        [__DIR__.'/src/Modules/Notifications'],
        [ErrorType::SHADOW_DEPENDENCY],
    )
    ->ignoreErrorsOnPackage('laravel/tinker', [ErrorType::UNUSED_DEPENDENCY]);
