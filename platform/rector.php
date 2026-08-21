<?php

declare(strict_types=1);

use Pest\Rector\Rules\EnsureTypeChecksFirstRector;
use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\CodingStyle\Rector\PostInc\PostIncDecToPreIncDecRector;
use Rector\Config\RectorConfig;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector;
use RectorLaravel\Rector\Class_\AddHasFactoryToModelsRector;
use RectorLaravel\Rector\Class_\BackoffPropertyToBackoffAttributeRector;
use RectorLaravel\Rector\Class_\ConnectionPropertyToConnectionAttributeRector;
use RectorLaravel\Rector\Class_\DelayPropertyToDelayAttributeRector;
use RectorLaravel\Rector\Class_\DeleteWhenMissingModelsPropertyToDeleteWhenMissingModelsAttributeRector;
use RectorLaravel\Rector\Class_\FailOnTimeoutPropertyToFailOnTimeoutAttributeRector;
use RectorLaravel\Rector\Class_\JobConnectionPropertyToJobConnectionAttributeRector;
use RectorLaravel\Rector\Class_\MaxExceptionsPropertyToMaxExceptionsAttributeRector;
use RectorLaravel\Rector\Class_\ModelCastsPropertyToCastsMethodRector;
use RectorLaravel\Rector\Class_\QueuePropertyToQueueAttributeRector;
use RectorLaravel\Rector\Class_\ReplaceExpectsMethodsInTestsRector;
use RectorLaravel\Rector\Class_\StopOnFirstFailurePropertyToStopOnFirstFailureAttributeRector;
use RectorLaravel\Rector\Class_\TablePropertyToTableAttributeRector;
use RectorLaravel\Rector\Class_\TimeoutPropertyToTimeoutAttributeRector;
use RectorLaravel\Rector\Class_\TriesPropertyToTriesAttributeRector;
use RectorLaravel\Rector\Class_\UniqueForPropertyToUniqueForAttributeRector;
use RectorLaravel\Rector\Class_\WithoutIncrementingPropertyToWithoutIncrementingAttributeRector;
use RectorLaravel\Rector\Class_\WithoutTimestampsPropertyToWithoutTimestampsAttributeRector;
use RectorLaravel\Rector\Coalesce\ApplyDefaultInsteadOfNullCoalesceRector;
use RectorLaravel\Rector\Empty_\EmptyToBlankAndFilledFuncRector;
use RectorLaravel\Rector\FuncCall\ConfigToTypedConfigMethodCallRector;
use RectorLaravel\Rector\MethodCall\RefactorBlueprintGeometryColumnsRector;
use RectorLaravel\Rector\PropertyFetch\ReplaceFakerInstanceWithHelperRector;
use RectorLaravel\Set\LaravelSetList;

$laravel13Attributes = [
    TablePropertyToTableAttributeRector::class,
    ConnectionPropertyToConnectionAttributeRector::class,
    WithoutIncrementingPropertyToWithoutIncrementingAttributeRector::class,
    WithoutTimestampsPropertyToWithoutTimestampsAttributeRector::class,
    StopOnFirstFailurePropertyToStopOnFirstFailureAttributeRector::class,
    TriesPropertyToTriesAttributeRector::class,
    TimeoutPropertyToTimeoutAttributeRector::class,
    BackoffPropertyToBackoffAttributeRector::class,
    MaxExceptionsPropertyToMaxExceptionsAttributeRector::class,
    JobConnectionPropertyToJobConnectionAttributeRector::class,
    QueuePropertyToQueueAttributeRector::class,
    DelayPropertyToDelayAttributeRector::class,
    DeleteWhenMissingModelsPropertyToDeleteWhenMissingModelsAttributeRector::class,
    FailOnTimeoutPropertyToFailOnTimeoutAttributeRector::class,
    UniqueForPropertyToUniqueForAttributeRector::class,
];

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withSkip([
        AddArrowFunctionReturnTypeRector::class,
        AddHasFactoryToModelsRector::class,
        AddOverrideAttributeToOverriddenMethodsRector::class,
        PostIncDecToPreIncDecRector::class,
        __DIR__.'/bootstrap/cache',
    ])
    ->withCache(cacheDirectory: __DIR__.'/.rector.result.cache', cacheClass: FileCacheStorage::class)
    ->withImportNames(removeUnusedImports: true)
    ->withRootFiles()
    ->withPhpSets()
    ->withComposerBased(laravel: true)
    ->withBootstrapFiles([__DIR__.'/vendor/larastan/larastan/bootstrap.php'])
    ->withPHPStanConfigs([__DIR__.'/phpstan.neon'])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        typeDeclarationDocblocks: true,
        privatization: true,
        namedArgs: true,
        instanceOf: true,
        earlyReturn: true,
        carbon: true,
        rectorPreset: true,
        phpunitCodeQuality: true,
    )
    ->withRules([
        ApplyDefaultInsteadOfNullCoalesceRector::class,
        EmptyToBlankAndFilledFuncRector::class,
        ModelCastsPropertyToCastsMethodRector::class,
        RefactorBlueprintGeometryColumnsRector::class,
        ReplaceExpectsMethodsInTestsRector::class,
        ReplaceFakerInstanceWithHelperRector::class,
        ConfigToTypedConfigMethodCallRector::class,
        EnsureTypeChecksFirstRector::class,
        ...$laravel13Attributes,
    ])
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
        LaravelSetList::LARAVEL_130_WITHOUT_ATTRIBUTES,
    ]);
