<?php

namespace ClaudioDekker\Keystone\Tests;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * A scratch git repository that runs the pre-commit hook over what a test stages.
 */
class HookRepository
{
    /**
     * Create a handle on the scratch repository at the given path.
     */
    public function __construct(public readonly string $path)
    {
        //
    }

    /**
     * Call the callback with a scratch repository holding a copy of the pre-commit hook, then remove the repository.
     *
     * @template TReturn
     *
     * @param  callable(static): TReturn  $callback
     * @return TReturn
     */
    public static function using(callable $callback): mixed
    {
        $repository = static::make();

        try {
            return $callback($repository);
        } finally {
            $repository->delete();
        }
    }

    /**
     * Make a scratch repository holding a copy of the pre-commit hook.
     */
    protected static function make(): static
    {
        $path = sys_get_temp_dir().'/keystone-hook-'.Str::random(8);

        File::ensureDirectoryExists($path);

        $repository = new static($path);
        $repository->git('init', '--quiet');

        File::ensureDirectoryExists($path.'/.githooks');
        File::copy(dirname(__DIR__).'/.githooks/pre-commit', $path.'/.githooks/pre-commit');
        File::chmod($path.'/.githooks/pre-commit', 0755);

        return $repository;
    }

    /**
     * Write a file of the given size in bytes into the working tree.
     */
    public function writeBytes(string $file, int $bytes): static
    {
        return $this->write($file, str_repeat('a', $bytes));
    }

    /**
     * Write a file with the given contents into the working tree.
     */
    public function write(string $file, string $contents): static
    {
        File::ensureDirectoryExists(dirname($this->path.'/'.$file));
        File::put($this->path.'/'.$file, $contents);

        return $this;
    }

    /**
     * Stage a file.
     */
    public function stage(string $file): static
    {
        $this->git('add', '--', $file);

        return $this;
    }

    /**
     * Commit what is staged without running the hook.
     */
    public function commitWithoutHook(): static
    {
        $this->git('-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '--quiet', '--no-verify', '-m', 'Seed');

        return $this;
    }

    /**
     * Stage the removal of a committed file.
     */
    public function stageDeletion(string $file): static
    {
        $this->git('rm', '--quiet', '--', $file);

        return $this;
    }

    /**
     * Run the pre-commit hook.
     */
    public function runHook(): ProcessResult
    {
        return Process::path($this->path)->run(['sh', '.githooks/pre-commit']);
    }

    /**
     * Remove the scratch repository.
     */
    protected function delete(): void
    {
        File::deleteDirectory($this->path);
    }

    /**
     * Run a git command in the scratch repository.
     */
    protected function git(string ...$arguments): void
    {
        Process::path($this->path)->run(['git', ...$arguments])->throw();
    }
}
