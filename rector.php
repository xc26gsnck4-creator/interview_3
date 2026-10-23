<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\PostInc\PostIncDecToPreIncDecRector;
use Rector\Config\RectorConfig;
use Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitSelfCallRector;
use Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitThisCallRector;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\DeclareStrictTypesRector;
use RectorLaravel\Rector\Class_\AddHasFactoryToModelsRector;
use RectorLaravel\Set\LaravelSetList;

/**
 * Left out on purpose: LARAVEL_STATIC_TO_INJECTION (injects constructors into
 * anonymous migrations and PHPUnit test cases), LARAVEL_LEGACY_FACTORIES_TO_CLASSES
 * (no legacy factories), and the naming and namedArgs prepared sets (rename
 * `Blueprint $table` to `$blueprint` in the migrations and name every boolean
 * argument in config). The PHPUnit narrow-asserts and mock-to-stub sets and the
 * PHPUnit attributes set are already part of phpunitCodeQuality and the
 * composer-based PHPUnit set.
 *
 * Tests call PHPUnit assertions statically (phpstan-strict-rules forbids dynamic
 * calls to static methods), so PreferPHPUnitSelfCallRector is added and its
 * opposite from phpunitCodeQuality is skipped.
 *
 * Skipped: the Notifications module, which the task provides and which stays
 * unchanged; PostIncDecToPreIncDecRector (rectorPreset), whose `++$i` Pint's
 * increment_style turns back into `$i++`; DeclareStrictTypesRector (rectorPreset),
 * which adds strict types without the analysis SafeDeclareStrictTypesRector
 * (codeQuality) does; AddHasFactoryToModelsRector (LARAVEL_FACTORIES), which adds
 * HasFactory to every model, with or without a factory.
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
    ->withRules([PreferPHPUnitSelfCallRector::class])
    ->withSkip([
        __DIR__.'/src/Modules/Notifications',
        PreferPHPUnitThisCallRector::class,
        PostIncDecToPreIncDecRector::class,
        DeclareStrictTypesRector::class,
        AddHasFactoryToModelsRector::class,
    ])
    ->withImportNames();
