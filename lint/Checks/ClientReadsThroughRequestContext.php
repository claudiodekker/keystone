<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\LaravelGuardrails\Check;
use ClaudioDekker\LaravelGuardrails\File;
use ClaudioDekker\LaravelGuardrails\Kind;
use ClaudioDekker\LaravelGuardrails\Violation;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;

/**
 * Refuse reading the client's address or user agent outside the request context, which captures them once per request.
 */
class ClientReadsThroughRequestContext extends Check
{
    /**
     * The request methods that read the client's address or user agent outright, lowercase.
     */
    protected const READERS = ['getclientip', 'getclientips', 'ip', 'ips', 'useragent'];

    /**
     * The header names that carry the user agent, lowercase.
     */
    protected const HEADERS = ['user-agent'];

    /**
     * The server variables that carry the client's address or user agent.
     */
    protected const SERVER_VARIABLES = ['HTTP_CLIENT_IP', 'HTTP_USER_AGENT', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];

    /**
     * Get the one-line description of the rule, shown when the checks are chosen.
     */
    public function description(): string
    {
        return "No read of the client's address or user agent in the source code.";
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
        return "Read the client's address and user agent through the request context.";
    }

    /**
     * Get a violation at each read of the client's address or user agent.
     *
     * @return list<Violation>
     */
    protected function violations(File $file): array
    {
        return array_map(Violation::at(...), $file->find(fn (Node $node) => $this->readsTheClient($node)));
    }

    /**
     * Determine if the node reads the client's address or user agent.
     */
    private function readsTheClient(Node $node): bool
    {
        if ($node instanceof Variable) {
            return $node->name === '_SERVER';
        }

        $name = $this->calledMethod($node);

        if ($name === null) {
            return false;
        }

        if (in_array($name, static::READERS, true)) {
            return true;
        }

        $literal = $this->firstLiteral($node);

        if ($literal === null) {
            return false;
        }

        return match ($name) {
            'hasheader', 'header' => in_array(strtolower($literal), static::HEADERS, true),
            'server' => in_array($literal, static::SERVER_VARIABLES, true),
            default => false,
        };
    }

    /**
     * Get the lowercase name of the method a call node invokes, if it is written out.
     */
    private function calledMethod(Node $node): ?string
    {
        $isCall = $node instanceof MethodCall
            || $node instanceof NullsafeMethodCall
            || $node instanceof StaticCall;

        if (! $isCall || ! $node->name instanceof Identifier) {
            return null;
        }

        return $node->name->toLowerString();
    }

    /**
     * Get the call's first argument when it is a string literal.
     */
    private function firstLiteral(MethodCall|NullsafeMethodCall|StaticCall $call): ?string
    {
        $argument = $call->args[0] ?? null;

        if (! $argument instanceof Arg || ! $argument->value instanceof String_) {
            return null;
        }

        return $argument->value->value;
    }
}
