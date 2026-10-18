<?php

use ClaudioDekker\Keystone\Tests\HookRepository;

it('refuses a staged file over the limit and names it with its token count', function () {
    $result = HookRepository::using(fn (HookRepository $repository) => $repository
        ->writeBytes('src/Big.php', 20001)
        ->stage('src/Big.php')
        ->runHook());

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())
        ->toContain('These staged files are over 5000 tokens (bytes / 4):')
        ->toContain('src/Big.php  5001 tokens')
        ->toContain('Split each one before you commit:');
});

it('lists every file over the limit', function () {
    $result = HookRepository::using(fn (HookRepository $repository) => $repository
        ->writeBytes('One.php', 24000)
        ->writeBytes('Two.php', 40000)
        ->writeBytes('Small.php', 100)
        ->stage('One.php')
        ->stage('Two.php')
        ->stage('Small.php')
        ->runHook());

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())
        ->toContain('One.php  6000 tokens')
        ->toContain('Two.php  10000 tokens')
        ->not->toContain('Small.php');
});

it('names a path with spaces', function () {
    $result = HookRepository::using(fn (HookRepository $repository) => $repository
        ->writeBytes('my docs/Big File.md', 20001)
        ->stage('my docs/Big File.md')
        ->runHook());

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('my docs/Big File.md  5001 tokens');
});

it('passes a staged file exactly at the limit', function () {
    $result = HookRepository::using(fn (HookRepository $repository) => $repository
        ->writeBytes('src/Edge.php', 20000)
        ->stage('src/Edge.php')
        ->runHook());

    expect($result->successful())->toBeTrue()
        ->and($result->errorOutput())->toBe('');
});

it('judges the staged content when the working copy shrinks afterwards', function () {
    $result = HookRepository::using(fn (HookRepository $repository) => $repository
        ->writeBytes('Big.php', 30000)
        ->stage('Big.php')
        ->writeBytes('Big.php', 100)
        ->runHook());

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('Big.php  7500 tokens');
});

it('judges the staged content when the working copy grows afterwards', function () {
    $result = HookRepository::using(fn (HookRepository $repository) => $repository
        ->writeBytes('Small.php', 100)
        ->stage('Small.php')
        ->writeBytes('Small.php', 30000)
        ->runHook());

    expect($result->successful())->toBeTrue();
});

it('skips a file marked as generated', function () {
    $result = HookRepository::using(fn (HookRepository $repository) => $repository
        ->write('.gitattributes', "*.lock linguist-generated\n")
        ->writeBytes('composer.lock', 40000)
        ->stage('composer.lock')
        ->runHook());

    expect($result->successful())->toBeTrue();
});

it('still refuses a file whose generated mark is unset', function () {
    $result = HookRepository::using(fn (HookRepository $repository) => $repository
        ->write('.gitattributes', "*.lock linguist-generated\nkeep.lock -linguist-generated\n")
        ->writeBytes('keep.lock', 40000)
        ->stage('keep.lock')
        ->runHook());

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('keep.lock  10000 tokens');
});

it('skips a binary file', function () {
    $result = HookRepository::using(fn (HookRepository $repository) => $repository
        ->write('image.bin', str_repeat("\0", 40000))
        ->stage('image.bin')
        ->runHook());

    expect($result->successful())->toBeTrue();
});

it('ignores a staged deletion', function () {
    $result = HookRepository::using(fn (HookRepository $repository) => $repository
        ->writeBytes('Big.php', 30000)
        ->stage('Big.php')
        ->commitWithoutHook()
        ->stageDeletion('Big.php')
        ->runHook());

    expect($result->successful())->toBeTrue();
});

it('ignores a file that is not staged', function () {
    $result = HookRepository::using(fn (HookRepository $repository) => $repository
        ->writeBytes('Big.php', 30000)
        ->runHook());

    expect($result->successful())->toBeTrue();
});
