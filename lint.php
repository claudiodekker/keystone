<?php

namespace ClaudioDekker\Keystone\Lint;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Collection;
use PhpParser\Node;
use PhpParser\Node\Expr;
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
