<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;

/**
 * Keep a package's tests in tests/Unit or tests/Feature.
 */
class TestsInUnitOrFeature extends Check
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/*/tests/*Test.php'];

    /**
     * The paths the check skips.
     *
     * @var list<string>
     */
    protected array $except = ['packages/*/tests/Unit/*', 'packages/*/tests/Feature/*'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return "Move the test into the package's tests/Unit or tests/Feature.";
    }

    /**
     * Get the line that breaks the rule, which is the whole file, for a test the check reads.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return [1];
    }
}
