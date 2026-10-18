<?php

namespace ClaudioDekker\Keystone\Tests;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Run `composer check` against stand-in tools, so a test decides which step fails.
 */
class CheckScript
{
    /**
     * Get the steps of the check script, as composer.json lists them.
     *
     * @return list<string>
     */
    public static function steps(): array
    {
        return (new Filesystem)->json(dirname(__DIR__).'/composer.json')['scripts']['check'];
    }

    /**
     * Make a directory of stand-in tools, where only the call that runs the failing step exits with 1.
     */
    public static function makeStandInTools(?string $failingStep): string
    {
        $directory = sys_get_temp_dir().'/keystone-check-'.Str::random(8);
        $calls = [];
        $failingCalls = [];

        File::ensureDirectoryExists($directory);

        foreach (static::steps() as $step) {
            if (str_starts_with($step, '@')) {
                continue;
            }

            $tool = Str::before($step, ' ');
            $calls[$tool] = ($calls[$tool] ?? 0) + 1;

            if ($step === $failingStep) {
                $failingCalls[$tool] = $calls[$tool];
            }

            $path = $directory.'/'.$tool;

            File::put($path, static::standIn($path, $failingCalls[$tool] ?? 0));
            File::chmod($path, 0755);
        }

        return $directory;
    }

    /**
     * Get a shell script that counts its calls in a file next to it and exits with 1 on the given call only.
     */
    protected static function standIn(string $path, int $failingCall): string
    {
        return <<<SHELL
        #!/bin/sh
        calls=\$(( \$(cat "{$path}.calls" 2>/dev/null || echo 0) + 1 ))
        echo "\$calls" > "{$path}.calls"
        [ "\$calls" -eq {$failingCall} ] && exit 1
        exit 0

        SHELL;
    }

    /**
     * Copy the root composer.json into the directory without its description, so `composer validate --strict` fails.
     */
    public static function copyInvalidManifest(string $directory): string
    {
        $manifest = File::json(dirname(__DIR__).'/composer.json');
        unset($manifest['description']);

        File::put($directory.'/composer.json', json_encode($manifest));

        return $directory.'/composer.json';
    }

    /**
     * Run `composer check` in the repository root with the given environment.
     *
     * @param  array<string, string>  $env
     */
    public static function run(array $env): ProcessResult
    {
        return Process::path(dirname(__DIR__))->env($env)->run(['composer', 'check']);
    }
}
