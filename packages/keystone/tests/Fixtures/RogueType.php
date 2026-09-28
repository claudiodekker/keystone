<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use ClaudioDekker\Keystone\Methods\Surface;
use Closure;

/**
 * A sign-in type whose verify answers whatever the test tells it to, and records what it was given.
 */
class RogueType implements CredentialType
{
    /**
     * The surface, input and credential ids of every verify call.
     *
     * @var list<array{Surface, array<string, mixed>, list<int>}>
     */
    public array $calls = [];

    /**
     * Create a new rogue type instance.
     *
     * @param  Closure(): Proof  $answer
     */
    public function __construct(
        protected Closure $answer,
    ) {
        //
    }

    public function name(): string
    {
        return 'rogue';
    }

    public function surfaces(): array
    {
        return ['sign-in' => InitiateShape::FORM];
    }

    public function isMultiFactorOnItsOwn(): bool
    {
        return false;
    }

    public function rules(Surface $surface): array
    {
        return ['secret' => ['nullable', 'string']];
    }

    public function verify(Surface $surface, array $input, array $credentials): Proof
    {
        $this->calls[] = [$surface, $input, array_map(fn (StoredCredential $credential) => $credential->id, $credentials)];

        return ($this->answer)();
    }
}
