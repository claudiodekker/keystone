<?php

namespace ClaudioDekker\Keystone\Lint;

use Illuminate\Process\Factory as ProcessFactory;
use PhpParser\Node;
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
        return static::$read[$path] ??= new static($path, file_get_contents(dirname(__DIR__).'/'.$path));
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
            ->path(dirname(__DIR__))
            ->run(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'])
            ->throw()
            ->output();

        return collect(explode("\0", $listing))
            ->filter(fn (string $path) => $path !== '' && is_file(dirname(__DIR__).'/'.$path))
            ->values()
            ->all();
    }
}
