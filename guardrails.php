<?php

use ClaudioDekker\Keystone\Lint\Checks\ClientReadsThroughRequestContext;
use ClaudioDekker\Keystone\Lint\Checks\NoContainerLookups;
use ClaudioDekker\Keystone\Lint\Checks\NoFactoriesInAppTests;
use ClaudioDekker\Keystone\Lint\Checks\NoHttpInFeatureTests;
use ClaudioDekker\Keystone\Lint\Checks\NoPackageDependencies;
use ClaudioDekker\Keystone\Lint\Checks\NoTableAccess;
use ClaudioDekker\Keystone\Lint\Checks\ResponsesThroughAssertionTraits;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Comments\DocumentedDeclarations;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Comments\SingleCaveatDocblocks;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Comments\SlopedComments;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Config\DurationsAsProducts;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Config\NoConfigDefaults;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Config\NoEnvOutsideConfig;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Config\SortedConfigKeys;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Enums\EnumCasesDocumentedAlike;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Enums\SortedEnumCases;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Safety\NoDebugCalls;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Safety\NoEval;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Safety\NoUnserialize;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Safety\NoWeakHashes;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Safety\NoWeakRandomness;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Shape\BlankLineAfterMultilineStatements;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Shape\NoBlankLineOpeningBlocks;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Shape\NoMatchOnCall;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Shape\NoNewThenCall;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Shape\OneClausePerLine;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Structure\NoFinal;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Structure\NoReflection;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Structure\NoTestCodeInApp;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Structure\OneClassPerFile;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Tests\NoDocblocksInTests;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Tests\NoFunctionsInTests;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Tests\TestsInUnitOrFeature;
use ClaudioDekker\LaravelGuardrails\Checks\Text\FilesWithinTokenBudget;
use ClaudioDekker\LaravelGuardrails\Checks\Text\NoEmDashes;

return [
    FilesWithinTokenBudget::class,
    NoEmDashes::class,
    NoDocblocksInTests::class,
    NoFunctionsInTests::class,
    (new TestsInUnitOrFeature)->only('packages/*/tests'),
    OneClassPerFile::class,
    NoFinal::class,
    NoReflection::class,
    NoTestCodeInApp::class,
    SortedEnumCases::class,
    EnumCasesDocumentedAlike::class,
    NoDebugCalls::class,
    NoEval::class,
    NoUnserialize::class,
    NoWeakHashes::class,
    NoWeakRandomness::class,
    NoMatchOnCall::class,
    NoNewThenCall::class,
    OneClausePerLine::class,
    NoBlankLineOpeningBlocks::class,
    BlankLineAfterMultilineStatements::class,
    SlopedComments::class,
    SingleCaveatDocblocks::class,
    DocumentedDeclarations::class,
    NoEnvOutsideConfig::class,
    NoConfigDefaults::class,
    DurationsAsProducts::class,
    SortedConfigKeys::class,
    (new ClientReadsThroughRequestContext)->allowing([
        'packages/keystone/src/Http/RequestContext.php' => 'The request context is where the client is read, once per request.',
    ]),
    NoContainerLookups::class,
    NoPackageDependencies::class,
    NoTableAccess::class,
    NoFactoriesInAppTests::class,
    ResponsesThroughAssertionTraits::class,
    (new NoHttpInFeatureTests)->allowing([
        'packages/keystone/tests/Feature/RateLimits/ReplacedActionTest.php' => "An app's AppTests run through its own controllers, so only core can arrange one that replaces an action outright.",
        'packages/keystone/tests/Feature/SignIn/RehashTest.php' => "Only core's fake type hands back a rehash on demand, or one that throws, so no app can arrange one.",
        'packages/keystone-inertia-vue/tests/Feature/Install/SignInPageTest.php' => "AppTests run in the app that ships them, so only the adapter's own tests can install into a copy of the skeleton and request the page the installer wired up.",
    ]),
];
