<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitThisCallRector;
use RectorLaravel\Set\LaravelSetList;

/**
 * Left out on purpose: LARAVEL_STATIC_TO_INJECTION (injects constructors into
 * anonymous migrations and PHPUnit test cases), LARAVEL_LEGACY_FACTORIES_TO_CLASSES
 * (no legacy factories), and the naming and namedArgs prepared sets (rename
 * parameters of the provided Notifications API and name every boolean argument).
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap/app.php',
        __DIR__.'/bootstrap/providers.php',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withComposerBased(phpunit: true, laravel: true)
    ->withPhpSets()
    ->withAttributesSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        typeDeclarationDocblocks: true,
        privatization: true,
        carbon: true,
        rectorPreset: true,
        phpunitCodeQuality: true,
        phpunitNarrowAsserts: true,
        phpunitMockToStub: true,
    )
    ->withSets([
        LaravelSetList::LARAVEL_ARRAYACCESS_TO_METHOD_CALL,
        LaravelSetList::LARAVEL_ARRAY_STR_FUNCTION_TO_STATIC_CALL,
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_CONTAINER_STRING_TO_FULLY_QUALIFIED_NAME,
        LaravelSetList::LARAVEL_ELOQUENT_MAGIC_METHOD_TO_QUERY_BUILDER,
        LaravelSetList::LARAVEL_FACADE_ALIASES_TO_FULL_NAMES,
        LaravelSetList::LARAVEL_FACTORIES,
        LaravelSetList::LARAVEL_IF_HELPERS,
        LaravelSetList::LARAVEL_TESTING,
        LaravelSetList::LARAVEL_TYPE_DECLARATIONS,
    ])
    ->withSkip([
        // The Notifications module is provided by the task and stays unchanged.
        __DIR__.'/src/Modules/Notifications',
        // Tests call PHPUnit assertions statically (phpstan-strict-rules forbids dynamic calls to static methods).
        PreferPHPUnitThisCallRector::class,
    ])
    ->withImportNames(removeUnusedImports: true);
