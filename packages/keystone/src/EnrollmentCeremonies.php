<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\PresentsCeremony;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 */
class EnrollmentCeremonies
{
    /**
     * The longest an enrollment ceremony runs.
     *
     * Its slot ends sooner when the pending sign-in it was opened under does, or a signed-in session's sudo or absolute lifetime.
     */
    public const int CAP_SECONDS = 900;

    /**
     * Create a new enrollment ceremonies instance.
     */
    public function __construct(
        protected KeystoneGuard $guard,
    ) {
        //
    }

    /**
     * Get the type's running enrollment ceremony, starting one for the account when there is none.
     */
    public function start(CredentialType $type, Model&KeystoneUser $account): RunningCeremony
    {
        $running = $this->running($type);

        if ($running !== null) {
            return $running;
        }

        $initiation = $type->initiate(Surface::ENROLLMENT, $this->accountName($account));
        $ceremony = ['ceremony' => $initiation?->ceremony, 'page' => $initiation->page ?? []];

        $this->guard->slots()->put($type->name(), Surface::ENROLLMENT->value, $ceremony, capSeconds: self::CAP_SECONDS);

        return $this->presented($type, $ceremony);
    }

    /**
     * Get the type's running enrollment ceremony, if one is live.
     */
    public function running(CredentialType $type): ?RunningCeremony
    {
        $kept = $this->guard->slots()->get($type->name(), Surface::ENROLLMENT->value);

        if (! is_array($kept)) {
            return null;
        }

        /** @var array{ceremony: mixed, page: array<string, string>} $kept */
        return $this->presented($type, $kept);
    }

    /**
     * Close the type's enrollment ceremony, forgetting what it made.
     */
    public function close(CredentialType $type): void
    {
        $this->guard->slots()->forget($type->name(), Surface::ENROLLMENT->value);
    }

    /**
     * Get the ceremony the session keeps as its form shows it, with what the type draws from the kept page on every request and the session never holds.
     *
     * @param  array{ceremony: mixed, page: array<string, string>}  $kept
     */
    protected function presented(CredentialType $type, #[\SensitiveParameter] array $kept): RunningCeremony
    {
        $page = $type instanceof PresentsCeremony ? $type->present($kept['page']) : $kept['page'];

        return new RunningCeremony($kept['ceremony'], $page);
    }

    /**
     * Get the name the account goes by in an authenticator: its first alert address, else its identifier.
     */
    protected function accountName(Model&KeystoneUser $account): string
    {
        return (new Addresses($account))->recipientsOf($account)[0] ?? (string) $account->getAuthIdentifier();
    }
}
