<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\LaravelGuardrails\Check;
use ClaudioDekker\LaravelGuardrails\File;
use ClaudioDekker\LaravelGuardrails\Kind;
use ClaudioDekker\LaravelGuardrails\Violation;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;

class CredentialWritesThroughAccountChange extends Check
{
    /**
     * The models whose rows only an account change writes.
     */
    protected const MODELS = [
        'ClaudioDekker\Keystone\Models\UserCredential',
        'ClaudioDekker\Keystone\Models\UserRecoveryCode',
    ];

    /**
     * The Eloquent and query builder methods that write rows, lowercase.
     */
    protected const WRITES = [
        'create',
        'createmany',
        'createorfirst',
        'createquietly',
        'decrement',
        'decrementeach',
        'delete',
        'deletequietly',
        'firstorcreate',
        'forcecreate',
        'forcecreatequietly',
        'forcedelete',
        'forcedeletequietly',
        'increment',
        'incrementeach',
        'insert',
        'insertgetid',
        'insertorignore',
        'insertusing',
        'restore',
        'save',
        'savemany',
        'savequietly',
        'touch',
        'truncate',
        'update',
        'updateorcreate',
        'updateorinsert',
        'updatequietly',
        'upsert',
    ];

    /**
     * Get the one-line description of the rule, shown when the checks are chosen.
     */
    public function description(): string
    {
        return 'No write in source code that names the credential or recovery code model.';
    }

    /**
     * Get the kinds of code the check reads.
     *
     * @return list<Kind>
     */
    public function kinds(): array
    {
        return [Kind::SOURCE];
    }

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Write credentials and recovery codes through an account change, so the write locks the account and moves its epoch when it ends anything.';
    }

    /**
     * Get a violation at each write in a file that names the credential model.
     *
     * @return list<Violation>
     */
    protected function violations(File $file): array
    {
        if ($file->find(fn (Node $node) => $this->isModel($node)) === []) {
            return [];
        }

        return array_map(Violation::at(...), $file->find(fn (Node $node) => $this->isWrite($node)));
    }

    /**
     * Determine if the node names one of the models.
     */
    private function isModel(Node $node): bool
    {
        return $node instanceof Name && in_array($node->toString(), static::MODELS, true);
    }

    /**
     * Determine if the node calls a method that writes rows.
     */
    private function isWrite(Node $node): bool
    {
        $isCall = $node instanceof MethodCall
            || $node instanceof NullsafeMethodCall
            || $node instanceof StaticCall;

        return $isCall
            && $node->name instanceof Identifier
            && in_array($node->name->toLowerString(), static::WRITES, true);
    }
}
