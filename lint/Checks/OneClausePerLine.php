<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Token;

/**
 * Require each clause of a condition of more than two clauses on its own line, operator first.
 */
class OneClausePerLine extends Check
{
    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Put each clause on its own line, starting with its operator.';
    }

    /**
     * Get the lines of the clauses that are not on their own line.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        $tokens = $file->tokens();
        $chains = collect($file->find(fn (Node $node) => $this->isBoolean($node)));

        $inner = $chains
            ->flatMap(fn (Node $chain) => [$chain->left, $chain->right])
            ->filter(fn (Node $side) => $this->continuesChain($side, $tokens))
            ->map(fn (Node $side) => spl_object_id($side));

        return $chains
            ->reject(fn (Node $chain) => $inner->contains(spl_object_id($chain)))
            ->map(fn (Node $chain) => $this->clauses($chain, $tokens))
            ->filter(fn (array $clauses) => count($clauses) > 2)
            ->flatMap(fn (array $clauses) => $this->misplacedLines($clauses, $tokens))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Determine if the node joins two clauses with a boolean operator.
     */
    protected function isBoolean(Node $node): bool
    {
        $operators = [
            Expr\BinaryOp\BooleanAnd::class,
            Expr\BinaryOp\BooleanOr::class,
            Expr\BinaryOp\LogicalAnd::class,
            Expr\BinaryOp\LogicalOr::class,
        ];

        return in_array($node::class, $operators, true);
    }

    /**
     * Determine if the node is a chain that continues the chain around it, because no parentheses wrap it.
     *
     * @param  list<Token>  $tokens
     */
    protected function continuesChain(Node $node, array $tokens): bool
    {
        return $this->isBoolean($node) && ! $this->isGrouped($node, $tokens);
    }

    /**
     * Get the clauses of the chain, looking through the sides that continue it.
     *
     * @param  list<Token>  $tokens
     * @return list<Node>
     */
    protected function clauses(Node $chain, array $tokens): array
    {
        return collect([$chain->left, $chain->right])
            ->flatMap(fn (Node $side) => $this->continuesChain($side, $tokens) ? $this->clauses($side, $tokens) : [$side])
            ->values()
            ->all();
    }

    /**
     * Get the lines of the clauses that do not start their own line, operator first.
     *
     * @param  list<Node>  $clauses
     * @param  list<Token>  $tokens
     * @return list<int>
     */
    protected function misplacedLines(array $clauses, array $tokens): array
    {
        return collect($clauses)
            ->reject(fn (Node $clause, int $index) => $this->isPlaced($clause, $index, $tokens))
            ->map(fn (Node $clause) => $tokens[$this->firstToken($clause, $tokens)]->line)
            ->values()
            ->all();
    }

    /**
     * Determine if the clause starts its own line, with its operator first.
     *
     * @param  list<Token>  $tokens
     */
    protected function isPlaced(Node $clause, int $index, array $tokens): bool
    {
        $first = $this->firstToken($clause, $tokens);

        if ($index > 0) {
            return $this->startsLine($this->previous($tokens, $first), $tokens);
        }

        return $tokens[$this->previous($tokens, $first)]->text !== '('
            || $this->startsLine($first, $tokens);
    }

    /**
     * Get the position of the first token of the clause, which is its opening parenthesis when it has one.
     *
     * @param  list<Token>  $tokens
     */
    protected function firstToken(Node $clause, array $tokens): int
    {
        $start = $clause->getStartTokenPos();

        return $this->isGrouped($clause, $tokens) ? $this->previous($tokens, $start) : $start;
    }

    /**
     * Determine if the node is wrapped in parentheses of its own.
     *
     * @param  list<Token>  $tokens
     */
    protected function isGrouped(Node $node, array $tokens): bool
    {
        $after = $this->next($tokens, $node->getEndTokenPos());

        return $tokens[$this->previous($tokens, $node->getStartTokenPos())]->text === '('
            && ($tokens[$after]->text ?? '') === ')';
    }

    /**
     * Determine if the token at the position is the first on its line.
     *
     * @param  list<Token>  $tokens
     */
    protected function startsLine(int $position, array $tokens): bool
    {
        return $position > 0
            && $tokens[$position - 1]->id === T_WHITESPACE
            && str_contains($tokens[$position - 1]->text, "\n");
    }

    /**
     * Get the position of the token before the position, skipping whitespace and comments.
     *
     * @param  list<Token>  $tokens
     */
    protected function previous(array $tokens, int $position): int
    {
        do {
            $position--;
        } while ($position >= 0 && $this->isTrivia($tokens[$position]));

        return $position;
    }

    /**
     * Get the position of the token after the position, skipping whitespace and comments.
     *
     * @param  list<Token>  $tokens
     */
    protected function next(array $tokens, int $position): int
    {
        do {
            $position++;
        } while (isset($tokens[$position]) && $this->isTrivia($tokens[$position]));

        return $position;
    }

    /**
     * Determine if the token is whitespace or a comment.
     */
    protected function isTrivia(Token $token): bool
    {
        return in_array($token->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }
}
