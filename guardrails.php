<?php

use ClaudioDekker\Keystone\Lint\Checks\ClientReadsThroughRequestContext;
use ClaudioDekker\Keystone\Lint\Checks\NoContainerLookups;
use ClaudioDekker\Keystone\Lint\Checks\NoFactoriesInAppTests;
use ClaudioDekker\Keystone\Lint\Checks\NoHttpInFeatureTests;
use ClaudioDekker\Keystone\Lint\Checks\NoPackageDependencies;
use ClaudioDekker\Keystone\Lint\Checks\NoTableAccess;
use ClaudioDekker\Keystone\Lint\Checks\ResponsesThroughAssertionTraits;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Comments\ClassDocblocksHoldOnlyTags;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Comments\DocumentedDeclarations;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Comments\PhpstanIgnoresNameIdentifier;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Comments\SingleCaveatDocblocks;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Comments\SlopedComments;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Config\DurationsAsProducts;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Config\NoConfigDefaults;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Config\NoEnvOutsideConfig;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Config\SortedConfigKeys;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Enums\EnumCasesDocumentedAlike;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Enums\SortedEnumCases;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Package\ClassesTaggedApiOrInternal;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Package\NoMixedTypes;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Safety\NoDebugCalls;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Safety\NoEval;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Safety\NoUnserialize;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Safety\NoWeakHashes;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Safety\NoWeakRandomness;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Shape\BlankLineAfterMultilineStatements;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Shape\NoBlankLineOpeningBlocks;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Shape\NoEmptyBraces;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Shape\NoNewThenCall;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Shape\NoParenthesesAroundCallReceivers;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Shape\NoParenthesisedReceiverInConditions;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Shape\OneClausePerLine;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Structure\ConcernsHoldOnlyTraits;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Structure\NoFinal;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Structure\NoReflection;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Structure\NoTestCodeInApp;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Structure\OneClassPerFile;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Tests\NoDocblocksInTests;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Tests\NoFunctionsInTests;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Tests\NoHttpInUnitTests;
use ClaudioDekker\LaravelGuardrails\Checks\Php\Tests\TestsInUnitOrFeature;
use ClaudioDekker\LaravelGuardrails\Checks\Text\FilesWithinTokenBudget;
use ClaudioDekker\LaravelGuardrails\Checks\Text\NoEmDashes;

return [
    FilesWithinTokenBudget::class,
    NoEmDashes::class,
    NoDocblocksInTests::class,
    NoFunctionsInTests::class,
    NoHttpInUnitTests::class,
    (new TestsInUnitOrFeature)->only('packages/*/tests'),
    OneClassPerFile::class,
    ConcernsHoldOnlyTraits::class,
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
    NoParenthesesAroundCallReceivers::class,
    NoParenthesisedReceiverInConditions::class,
    NoNewThenCall::class,
    OneClausePerLine::class,
    NoBlankLineOpeningBlocks::class,
    NoEmptyBraces::class,
    BlankLineAfterMultilineStatements::class,
    SlopedComments::class,
    SingleCaveatDocblocks::class,
    ClassDocblocksHoldOnlyTags::class,
    PhpstanIgnoresNameIdentifier::class,
    DocumentedDeclarations::class,
    NoEnvOutsideConfig::class,
    NoConfigDefaults::class,
    DurationsAsProducts::class,
    SortedConfigKeys::class,
    new ClassesTaggedApiOrInternal(['packages/*/src']),
    (new NoMixedTypes(['packages/*/src']))->allowing([
        'packages/keystone/src/Configuration/KeyChecks.php' => 'It checks config values, which hold whatever the app wrote.',
        'packages/keystone/src/Http/HardeningSettings.php' => 'It parses config values, which hold whatever the app wrote.',
        'packages/keystone/src/KeystoneServiceProvider.php' => 'It checks config values, which hold whatever the app wrote.',
        'packages/keystone/src/Methods/MethodAllowList.php' => 'It parses the keystone.methods config value, which holds whatever the app wrote.',
    ]),
    (new ClientReadsThroughRequestContext)->allowing([
        'packages/keystone/src/Http/RequestContext.php' => 'The request context is where the client is read, once per request.',
    ]),
    NoContainerLookups::class,
    NoPackageDependencies::class,
    NoTableAccess::class,
    NoFactoriesInAppTests::class,
    ResponsesThroughAssertionTraits::class,
    (new NoHttpInFeatureTests)->allowing([
        'packages/keystone/tests/Feature/Http/AppRoutesTest.php' => "It checks that the app's own routes keep their headers, get no cross-site check, and clear site data when they sign the user out. AppTests serve only Keystone's routes and one empty page behind auth, so none can check a route the app owns.",
        'packages/keystone/tests/Feature/RateLimits/ReplacedActionTest.php' => "It checks that rate limiting, the hardening floor and the cross-site check still hold for a controller that overrides show() and store() without calling Keystone's. The stub controllers always call Keystone's, so no AppTest runs one that skips them.",
        'packages/keystone/tests/Feature/SignIn/RehashTest.php' => "It needs core's fake credential type, which rehashes, throws or changes the secret mid-check on demand. A real app's type cannot be made to, so no AppTest can arrange it.",
        'packages/keystone-inertia-vue/tests/Feature/Install/SignInPageTest.php' => 'It runs keystone:install on a copy of the Laravel skeleton, then requests /login from what it installed. AppTests run in an app that is already installed, so none can run the installer first.',
    ]),
];
