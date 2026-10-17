<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

/**
 * Refuse reading the client's address or user agent anywhere in src but the request context, which captures them once per request.
 */
class ClientReadsThroughRequestContext extends Check
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/*/src/*.php'];

    /**
     * The paths the check skips.
     *
     * @var list<string>
     */
    protected array $except = ['packages/keystone/src/Http/RequestContext.php'];

    /**
     * The request methods that read the client's address or user agent outright, lowercase.
     *
     * @var list<string>
     */
    protected array $readers = ['getclientip', 'getclientips', 'ip', 'ips', 'useragent'];

    /**
     * The header names that carry the user agent, lowercase.
     *
     * @var list<string>
     */
    protected array $headers = ['user-agent'];

    /**
     * The server variables that carry the client's address or user agent.
     *
     * @var list<string>
     */
    protected array $serverVariables = ['HTTP_CLIENT_IP', 'HTTP_USER_AGENT', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return "Read the client's address and user agent through the request context.";
    }

    /**
     * Get the lines that read the client's address or user agent.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $this->readsTheClient($node)));
    }

    /**
     * Determine if the node reads the client's address or user agent.
     */
    protected function readsTheClient(Node $node): bool
    {
        if ($node instanceof Expr\Variable) {
            return $node->name === '_SERVER';
        }

        $name = $this->methodName($node);

        if ($name === null) {
            return false;
        }

        if (in_array($name, $this->readers, true)) {
            return true;
        }

        $literal = $this->firstLiteral($node);

        if ($literal === null) {
            return false;
        }

        return match ($name) {
            'hasheader', 'header' => in_array(strtolower($literal), $this->headers, true),
            'server' => in_array($literal, $this->serverVariables, true),
            default => false,
        };
    }

    /**
     * Get the lowercase name of the method a call node invokes, if it is written out.
     */
    protected function methodName(Node $node): ?string
    {
        $calls = [Expr\MethodCall::class, Expr\NullsafeMethodCall::class, Expr\StaticCall::class];

        if (! in_array($node::class, $calls, true) || ! $node->name instanceof Node\Identifier) {
            return null;
        }

        return $node->name->toLowerString();
    }

    /**
     * Get the call's first argument when it is a string literal.
     */
    protected function firstLiteral(Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall $call): ?string
    {
        $argument = $call->args[0] ?? null;

        if (! $argument instanceof Node\Arg || ! $argument->value instanceof Scalar\String_) {
            return null;
        }

        return $argument->value->value;
    }
}
