<?php

use ClaudioDekker\Keystone\Tests\CheckScript;
use Illuminate\Support\Facades\File;

it('fails when one of its steps fails', function (string $step) {
    $tools = CheckScript::makeStandInTools($step);

    $env = match ($step) {
        '@composer validate --strict' => ['COMPOSER' => CheckScript::copyInvalidManifest($tools)],
        '@composer audit' => ['COMPOSER_DISABLE_NETWORK' => '1'],
        default => [],
    };

    try {
        $result = CheckScript::run([...$env, 'COMPOSER_BIN_DIR' => $tools]);
    } finally {
        File::deleteDirectory($tools);
    }

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain("Script {$step} handling the check event returned with error code");
})->with(function () {
    $steps = array_filter(CheckScript::steps(), fn (string $step) => ! str_starts_with($step, '@putenv'));

    return array_combine($steps, $steps);
});

it('passes when every step passes', function () {
    $tools = CheckScript::makeStandInTools(null);

    try {
        $result = CheckScript::run(['COMPOSER_BIN_DIR' => $tools]);
    } finally {
        File::deleteDirectory($tools);
    }

    expect($result->successful())->toBeTrue();
});
