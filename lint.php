<?php

namespace ClaudioDekker\Keystone\Lint;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Collection;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\Token;

/**
 * A file of the repository, parsed when a check first asks.
 */
class File
{
    /**
     * The files already read, by path.
     *
     * @var array<string, static>
     */
    protected static array $read = [];

    /**
     * The paths git tracks, plus new files it doesn't ignore.
     *
     * @var list<string>|null
     */
    protected static ?array $paths = null;

    /**
     * The file's statements, with names resolved.
     *
     * @var array<Stmt>|null
     */
    protected ?array $statements = null;

    /**
     * The file's tokens.
     *
     * @var list<Token>
     */
    protected array $tokens = [];

    /**
     * Create a file from its repository path and contents.
     */
    public function __construct(public string $path, public string $contents)
    {
        //
    }

    /**
     * Read the file at the given repository path.
     */
    public static function read(string $path): static
    {
        return static::$read[$path] ??= new static($path, file_get_contents(__DIR__.'/'.$path));
    }

    /**
     * Get every path git tracks, plus new files it doesn't ignore.
     *
     * @return list<string>
     */
    public static function paths(): array
    {
        return static::$paths ??= static::listPaths();
    }

    /**
     * Get the file's statements, with every class and function name resolved.
     *
     * @return array<Stmt>
     */
    public function statements(): array
    {
        if ($this->statements === null) {
            $this->parse();
        }

        return $this->statements;
    }

    /**
     * Get the file's tokens.
     *
     * @return list<Token>
     */
    public function tokens(): array
    {
        $this->statements();

        return $this->tokens;
    }

    /**
     * Find the nodes the given test accepts.
     *
     * @param  callable(Node): bool  $test
     * @return list<Node>
     */
    public function find(callable $test): array
    {
        return (new NodeFinder)->find($this->statements(), $test);
    }

    /**
     * Get the distinct start lines of the given nodes, in order.
     *
     * @param  list<Node>  $nodes
     * @return list<int>
     */
    public function lines(array $nodes): array
    {
        return collect($nodes)
            ->map(fn (Node $node) => $node->getStartLine())
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Parse the file into statements and tokens.
     */
    protected function parse(): void
    {
        $parser = (new ParserFactory)->createForHostVersion();

        $statements = $parser->parse($this->contents) ?? [];

        $this->tokens = $parser->getTokens();
        $this->statements = (new NodeTraverser(new NameResolver))->traverse($statements);
    }

    /**
     * List the paths git tracks, plus new files it doesn't ignore.
     *
     * @return list<string>
     */
    protected static function listPaths(): array
    {
        $listing = (new ProcessFactory)
            ->path(__DIR__)
            ->run(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'])
            ->throw()
            ->output();

        return collect(explode("\0", $listing))
            ->filter(fn (string $path) => $path !== '' && is_file(__DIR__.'/'.$path))
            ->values()
            ->all();
    }
}

/**
 * A rule the repository holds to.
 */
abstract class Check
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['*.php'];

    /**
     * The paths the check skips.
     *
     * @var list<string>
     */
    protected array $except = [];

    /**
     * Get the failure message, naming the fix.
     */
    abstract public function message(): string;

    /**
     * Get the lines of the file that break the rule.
     *
     * @return list<int>
     */
    abstract protected function lines(File $file): array;

    /**
     * Get the lines of the file that break the rule, if the check reads the file.
     *
     * @return list<int>
     */
    public function check(File $file): array
    {
        if (! $this->reads($file->path)) {
            return [];
        }

        return $this->lines($file);
    }

    /**
     * Get every break of the rule in the repository, as "path:line message".
     *
     * @return list<string>
     */
    public function run(): array
    {
        return collect(File::paths())
            ->flatMap(fn (string $path) => $this->report($path))
            ->values()
            ->all();
    }

    /**
     * Get the breaks of the rule in the file at the given path, as "path:line message".
     *
     * @return list<string>
     */
    protected function report(string $path): array
    {
        if (! $this->reads($path)) {
            return [];
        }

        return collect($this->lines(File::read($path)))
            ->map(fn (int $line) => "{$path}:{$line} {$this->message()}")
            ->all();
    }

    /**
     * Determine if the check reads the file at the given path.
     */
    protected function reads(string $path): bool
    {
        return $this->matches($this->paths, $path) && ! $this->matches($this->except, $path);
    }

    /**
     * Determine if the path matches any of the patterns.
     *
     * @param  list<string>  $patterns
     */
    protected function matches(array $patterns, string $path): bool
    {
        return collect($patterns)->contains(fn (string $pattern) => fnmatch($pattern, $path));
    }
}

/**
 * Refuse calls to a set of global functions.
 */
abstract class FunctionCalls extends Check
{
    /**
     * The refused functions, lowercase.
     *
     * @var list<string>
     */
    protected array $functions = [];

    /**
     * Get the lines that call a refused function.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $this->isRefusedCall($node)));
    }

    /**
     * Determine if the node calls a refused function by name.
     */
    protected function isRefusedCall(Node $node): bool
    {
        return $node instanceof Expr\FuncCall
            && $node->name instanceof Node\Name
            && in_array($node->name->toLowerString(), $this->functions, true);
    }
}

/**
 * Refuse a call that prints a value and stops, or leaves the value for a debugger.
 */
class NoDebugCalls extends FunctionCalls
{
    /**
     * The refused functions, lowercase.
     *
     * @var list<string>
     */
    protected array $functions = ['dd', 'dump', 'ray', 'var_dump'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Remove the debug call.';
    }

    /**
     * Determine if the node calls a refused function or method by name.
     */
    protected function isRefusedCall(Node $node): bool
    {
        if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
            return $node->name instanceof Node\Identifier
                && in_array($node->name->toLowerString(), $this->functions, true);
        }

        return parent::isRefusedCall($node);
    }
}

/**
 * Refuse src that depends on tests.
 */
class NoTestDependencies extends Check
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/*/src/*.php'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Move what src needs out of tests and into src.';
    }

    /**
     * Get the lines that name a class from a tests namespace.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $node instanceof Node\Name && $this->isTestClass($node)));
    }

    /**
     * Determine if the name is in a tests namespace of Keystone.
     */
    protected function isTestClass(Node\Name $name): bool
    {
        return str_starts_with($name->toString(), 'ClaudioDekker\\Keystone\\')
            && in_array('Tests', $name->getParts(), true);
    }
}

/**
 * Refuse core that depends on a method or adapter package.
 */
class NoPackageDependencies extends Check
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/keystone/src/*.php'];

    /**
     * The namespaces of the packages core must not use.
     *
     * @var list<string>
     */
    protected array $namespaces;

    /**
     * Create the check, reading the other packages' namespaces from their manifests unless they are given.
     *
     * @param  list<string>|null  $namespaces
     */
    public function __construct(?array $namespaces = null)
    {
        $this->namespaces = $namespaces ?? $this->packageNamespaces();
    }

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Move the shared code into core; core never depends on a method or adapter package.';
    }

    /**
     * Get the lines that name a class from another package.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $node instanceof Node\Name && $this->isOtherPackage($node)));
    }

    /**
     * Determine if the name is in the namespace of another package.
     */
    protected function isOtherPackage(Node\Name $name): bool
    {
        return collect($this->namespaces)->contains(fn (string $namespace) => str_starts_with($name->toString().'\\', $namespace));
    }

    /**
     * Get the namespaces every package but core maps in its manifest.
     *
     * @return list<string>
     */
    protected function packageNamespaces(): array
    {
        $files = new Filesystem;

        return collect($files->glob(__DIR__.'/packages/*/composer.json'))
            ->reject(fn (string $path) => basename(dirname($path)) === 'keystone')
            ->flatMap(fn (string $path) => array_keys($files->json($path)['autoload']['psr-4'] ?? []))
            ->values()
            ->all();
    }
}

/**
 * Refuse a final declaration in src.
 */
class NoFinal extends Check
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/*/src/*.php'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return "Remove final; apps extend Keystone's classes.";
    }

    /**
     * Get the lines that declare a final class, method, constant or property.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $this->isFinal($node)));
    }

    /**
     * Determine if the node declares something final.
     */
    protected function isFinal(Node $node): bool
    {
        $declarations = [Stmt\Class_::class, Stmt\ClassConst::class, Stmt\ClassMethod::class, Stmt\Property::class];

        return in_array($node::class, $declarations, true) && $node->isFinal();
    }
}

/**
 * Require a docblock on every class, function and property in src and in test support code.
 */
class DocumentedDeclarations extends Check
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/*/src/*.php', 'packages/*/tests/*.php', 'tests/*.php', 'lint.php'];

    /**
     * The paths the check skips.
     *
     * @var list<string>
     */
    protected array $except = ['*Test.php'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Add a docblock: one imperative line saying what it does.';
    }

    /**
     * Get the lines that declare something without a docblock.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $this->needsDocblock($node) && $node->getDocComment() === null));
    }

    /**
     * Determine if the node is a declaration that needs a docblock.
     */
    protected function needsDocblock(Node $node): bool
    {
        if ($node instanceof Stmt\ClassLike) {
            return $node->name !== null;
        }

        return $node instanceof Stmt\ClassMethod
            || $node instanceof Stmt\Function_
            || $node instanceof Stmt\Property;
    }
}

/**
 * Refuse a docblock in a test case, because the test's name says what it does.
 */
class NoDocblocksInTests extends Check
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['*Test.php'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return "Remove the docblock; the test's name says what it does.";
    }

    /**
     * Get the lines that open a docblock.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return collect($file->tokens())
            ->filter(fn (Token $token) => $token->id === T_DOC_COMMENT)
            ->map(fn (Token $token) => $token->line)
            ->values()
            ->all();
    }
}

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

/**
 * Require a blank line after a statement that spans several lines.
 */
class BlankLineAfterMultilineStatements extends Check
{
    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Add a blank line after the statement that spans several lines.';
    }

    /**
     * Get the first lines of the statements that follow a multiline statement without a blank line.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        $blocks = collect($file->find(fn (Node $node) => $this->hasStatements($node)))
            ->map(fn (Node $node) => $node->stmts)
            ->prepend($file->statements());

        return $blocks
            ->flatMap(fn (array $statements) => $this->crowded($statements))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Determine if the node holds a block of statements.
     */
    protected function hasStatements(Node $node): bool
    {
        return in_array('stmts', $node->getSubNodeNames(), true) && is_array($node->stmts);
    }

    /**
     * Get the first lines of the statements that directly follow a multiline statement.
     *
     * @param  array<Node>  $statements
     * @return list<int>
     */
    protected function crowded(array $statements): array
    {
        return collect($statements)
            ->sliding(2)
            ->filter(fn (Collection $pair) => $this->isCrowded($pair->first(), $pair->last()))
            ->map(fn (Collection $pair) => $this->firstLine($pair->last()))
            ->values()
            ->all();
    }

    /**
     * Determine if the statement spans lines and the next one starts on the line after it.
     */
    protected function isCrowded(Node $statement, Node $next): bool
    {
        return $statement->getEndLine() > $statement->getStartLine()
            && $this->firstLine($next) === $statement->getEndLine() + 1;
    }

    /**
     * Get the line where the statement starts, counting the comment above it.
     */
    protected function firstLine(Node $statement): int
    {
        $comments = $statement->getComments();

        return $comments === [] ? $statement->getStartLine() : $comments[0]->getStartLine();
    }
}

/**
 * Refuse a block that opens with a blank line.
 */
class NoBlankLineOpeningBlocks extends Check
{
    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Remove the blank line that opens the block.';
    }

    /**
     * Get the blank lines that directly follow an opening brace.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        $tokens = $file->tokens();

        return collect($tokens)
            ->filter(fn (Token $token, int $index) => $token->text === '{' && $this->isBlank($tokens[$index + 1] ?? null))
            ->map(fn (Token $token) => $token->line + 1)
            ->values()
            ->all();
    }

    /**
     * Determine if the token is whitespace that holds a blank line.
     */
    protected function isBlank(?Token $token): bool
    {
        return $token?->id === T_WHITESPACE && substr_count($token->text, "\n") > 1;
    }
}

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

/**
 * Check the cases of every enum.
 */
abstract class EnumCases extends Check
{
    /**
     * Get the cases of the enum that break the rule.
     *
     * @param  list<Stmt\EnumCase>  $cases
     * @return list<Stmt\EnumCase>
     */
    abstract protected function offending(array $cases): array;

    /**
     * Get the lines of the enum cases that break the rule.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return collect($file->find(fn (Node $node) => $node instanceof Stmt\Enum_))
            ->flatMap(fn (Stmt\Enum_ $enum) => $this->offending($this->cases($enum)))
            ->map(fn (Stmt\EnumCase $case) => $case->getStartLine())
            ->values()
            ->all();
    }

    /**
     * Get the cases of the enum.
     *
     * @return list<Stmt\EnumCase>
     */
    protected function cases(Stmt\Enum_ $enum): array
    {
        return collect($enum->stmts)
            ->filter(fn (Node $statement) => $statement instanceof Stmt\EnumCase)
            ->values()
            ->all();
    }
}

/**
 * Require the cases of an enum in alphabetical order.
 */
class SortedEnumCases extends EnumCases
{
    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return "Sort the enum's cases alphabetically.";
    }

    /**
     * Get the cases that sort before the case above them.
     *
     * @param  list<Stmt\EnumCase>  $cases
     * @return list<Stmt\EnumCase>
     */
    protected function offending(array $cases): array
    {
        return collect($cases)
            ->sliding(2)
            ->filter(fn (Collection $pair) => strcmp($pair->last()->name->toString(), $pair->first()->name->toString()) < 0)
            ->map(fn (Collection $pair) => $pair->last())
            ->values()
            ->all();
    }
}

/**
 * Require an enum to document every case or none.
 */
class EnumCasesDocumentedAlike extends EnumCases
{
    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Document every case of the enum, or none.';
    }

    /**
     * Get the undocumented cases of an enum that documents some of its cases.
     *
     * @param  list<Stmt\EnumCase>  $cases
     * @return list<Stmt\EnumCase>
     */
    protected function offending(array $cases): array
    {
        $undocumented = collect($cases)->filter(fn (Stmt\EnumCase $case) => $case->getDocComment() === null);

        if ($undocumented->count() === count($cases)) {
            return [];
        }

        return $undocumented->values()->all();
    }
}

/**
 * Refuse table and connection access, so storage goes through models.
 */
class NoTableAccess extends Check
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/*/src/*.php', 'packages/*/tests/*.php'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Go through the model; apps may rename the table.';
    }

    /**
     * Get the lines that call table() or getConnection().
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $this->isTableAccess($node)));
    }

    /**
     * Determine if the node calls getConnection(), or table() on the query builder or the DB facade.
     */
    protected function isTableAccess(Node $node): bool
    {
        $name = $this->memberName($node);

        if ($name === 'getconnection') {
            return true;
        }

        if ($name !== 'table') {
            return false;
        }

        return ! $node instanceof Expr\StaticCall || $this->isDatabaseFacade($node->class);
    }

    /**
     * Get the lowercase name of the method a call node invokes, if it is written out.
     */
    protected function memberName(Node $node): ?string
    {
        $calls = [Expr\MethodCall::class, Expr\NullsafeMethodCall::class, Expr\StaticCall::class];

        if (! in_array($node::class, $calls, true) || ! $node->name instanceof Node\Identifier) {
            return null;
        }

        return $node->name->toLowerString();
    }

    /**
     * Determine if the class of a static call is the DB facade, under whatever name the file imports it.
     */
    protected function isDatabaseFacade(Node $class): bool
    {
        return $class instanceof Node\Name
            && in_array($class->toString(), ['DB', 'Illuminate\\Support\\Facades\\DB'], true);
    }
}

/**
 * Refuse a container lookup inside a method in src, so services are injected.
 */
class NoContainerLookups extends FunctionCalls
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/*/src/*.php'];

    /**
     * The refused functions, lowercase.
     *
     * @var list<string>
     */
    protected array $functions = ['app', 'resolve'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Inject the service through the constructor instead.';
    }

    /**
     * Get the lines that look up a service inside a method.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        $calls = collect($file->find(fn (Node $node) => $node instanceof Stmt\ClassMethod))
            ->flatMap(fn (Stmt\ClassMethod $method) => (new NodeFinder)->find($method->stmts ?? [], fn (Node $node) => $this->isRefusedCall($node)))
            ->all();

        return $file->lines($calls);
    }
}

/**
 * Refuse a call on a new object when both the constructor and the call take several arguments.
 */
class NoNewThenCall extends Check
{
    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Assign the new object to a variable, then call the method on it.';
    }

    /**
     * Get the lines of the calls on a new object.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $this->isNewThenCall($node)));
    }

    /**
     * Determine if the node is a call on a new object, with several arguments on both.
     */
    protected function isNewThenCall(Node $node): bool
    {
        return ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall)
            && $node->var instanceof Expr\New_
            && count($node->var->args) > 1
            && count($node->args) > 1;
    }
}

/**
 * Refuse a match whose subject is a call.
 */
class NoMatchOnCall extends Check
{
    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return "Name the call's result in a variable, then match on the variable.";
    }

    /**
     * Get the lines of the matches on a call.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $node instanceof Expr\Match_ && $node->cond instanceof Expr\CallLike));
    }
}

/**
 * Refuse env() outside the config files.
 */
class NoEnvOutsideConfig extends FunctionCalls
{
    /**
     * The paths the check skips.
     *
     * @var list<string>
     */
    protected array $except = ['packages/*/config/*'];

    /**
     * The refused functions, lowercase.
     *
     * @var list<string>
     */
    protected array $functions = ['env'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Read the value through config(); only config files call env().';
    }
}

/**
 * Refuse a config read with an inline default.
 */
class NoConfigDefaults extends Check
{
    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Remove the inline default; the config file holds it.';
    }

    /**
     * Get the lines that read a config value with an inline default.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $this->readsWithDefault($node)));
    }

    /**
     * Determine if the node reads a config value and passes a default.
     */
    protected function readsWithDefault(Node $node): bool
    {
        if ($node instanceof Expr\FuncCall) {
            return $this->isConfigFunction($node) && count($node->args) > 1;
        }

        if ($node instanceof Expr\MethodCall) {
            return $node->var instanceof Expr\FuncCall
                && $this->isConfigFunction($node->var)
                && $node->var->args === []
                && $this->isRead($node);
        }

        return $node instanceof Expr\StaticCall
            && $node->class instanceof Node\Name
            && in_array($node->class->toString(), ['Config', 'Illuminate\\Support\\Facades\\Config'], true)
            && $this->isRead($node);
    }

    /**
     * Determine if the node calls the config() function.
     */
    protected function isConfigFunction(Expr\FuncCall $call): bool
    {
        return $call->name instanceof Node\Name && $call->name->toLowerString() === 'config';
    }

    /**
     * Determine if the call reads a value and passes a default.
     */
    protected function isRead(Expr\MethodCall|Expr\StaticCall $call): bool
    {
        return $call->name instanceof Node\Identifier
            && in_array($call->name->toLowerString(), ['array', 'boolean', 'float', 'get', 'integer', 'string'], true)
            && count($call->args) > 1;
    }
}

/**
 * Require a duration in seconds in a shipped config file as a product of its units.
 */
class DurationsAsProducts extends Check
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/*/config/*.php'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Write the duration as a product of its units, such as 12 * 60 * 60.';
    }

    /**
     * Get the lines of the durations written as a total.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $this->isTotalOfMinutes($node)));
    }

    /**
     * Determine if the node is a "_seconds" item whose value is more than a minute and a whole number of minutes.
     */
    protected function isTotalOfMinutes(Node $node): bool
    {
        return $node instanceof Expr\ArrayItem
            && $node->key instanceof Scalar\String_
            && str_ends_with($node->key->value, '_seconds')
            && $node->value instanceof Scalar\Int_
            && $node->value->value > 60
            && $node->value->value % 60 === 0;
    }
}

/**
 * Require the keys of every array in a shipped config file in alphabetical order.
 */
class SortedConfigKeys extends Check
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/*/config/*.php'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return "Sort the array's keys alphabetically.";
    }

    /**
     * Get the lines of the keys that sort before the key above them.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return collect($file->find(fn (Node $node) => $node instanceof Expr\Array_ && $this->hasOnlyNamedKeys($node)))
            ->flatMap(fn (Expr\Array_ $array) => $this->unsorted($array))
            ->map(fn (Expr\ArrayItem $item) => $item->getStartLine())
            ->values()
            ->all();
    }

    /**
     * Determine if every item of the array has a string key.
     */
    protected function hasOnlyNamedKeys(Expr\Array_ $array): bool
    {
        return $array->items !== []
            && collect($array->items)->every(fn (?Expr\ArrayItem $item) => $item?->key instanceof Scalar\String_);
    }

    /**
     * Get the items whose key sorts before the key of the item above them.
     *
     * @return list<Expr\ArrayItem>
     */
    protected function unsorted(Expr\Array_ $array): array
    {
        return collect($array->items)
            ->sliding(2)
            ->filter(fn (Collection $pair) => strcmp($pair->last()->key->value, $pair->first()->key->value) < 0)
            ->map(fn (Collection $pair) => $pair->last())
            ->values()
            ->all();
    }
}

/**
 * Refuse reflection in src.
 */
class NoReflection extends Check
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/*/src/*.php'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Call the code directly, or give it a protected method to override.';
    }

    /**
     * Get the lines that name a reflection class.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $node instanceof Node\Name && $this->isReflection($node)));
    }

    /**
     * Determine if the name is one of PHP's reflection classes or the framework's reflector.
     */
    protected function isReflection(Node\Name $name): bool
    {
        return preg_match('/^Reflection[A-Za-z]*$/', $name->toString()) === 1
            || $name->toString() === 'Illuminate\\Support\\Reflector';
    }
}

/**
 * Refuse unserialize() in src.
 */
class NoUnserialize extends FunctionCalls
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/*/src/*.php'];

    /**
     * The refused functions, lowercase.
     *
     * @var list<string>
     */
    protected array $functions = ['unserialize'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Store JSON and read it with json_decode().';
    }
}

/**
 * Refuse eval() in src.
 */
class NoEval extends Check
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/*/src/*.php'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Remove eval() and write the code out.';
    }

    /**
     * Get the lines that call eval().
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $node instanceof Expr\Eval_));
    }
}

/**
 * Refuse md5() and sha1() in src.
 */
class NoWeakHashes extends FunctionCalls
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/*/src/*.php'];

    /**
     * The refused functions, lowercase.
     *
     * @var list<string>
     */
    protected array $functions = ['md5', 'sha1'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return "Use hash('sha256', ...) or hash_hmac() instead.";
    }
}

/**
 * Refuse rand(), mt_rand() and uniqid() in src.
 */
class NoWeakRandomness extends FunctionCalls
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/*/src/*.php'];

    /**
     * The refused functions, lowercase.
     *
     * @var list<string>
     */
    protected array $functions = ['mt_rand', 'rand', 'uniqid'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Use random_int() or Str::random() instead.';
    }
}
