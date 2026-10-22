<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\LaravelGuardrails\ForbiddenCalls;
use Illuminate\Support\Facades\DB;

/**
 * Refuse table and connection access, so storage goes through models.
 */
class NoTableAccess extends ForbiddenCalls
{
    /**
     * The methods the rule forbids on any object.
     *
     * @var list<string>
     */
    protected array $methods = ['getConnection', 'table'];

    /**
     * The static calls the rule forbids, on the DB facade and its global alias.
     *
     * @var array<class-string|string, list<string>>
     */
    protected array $staticCalls = [
        'DB' => ['getConnection', 'table'],
        DB::class => ['getConnection', 'table'],
    ];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Go through the model, because apps may rename the table.';
    }
}
