<?php

namespace ClaudioDekker\Keystone\Http\Concerns;

use ClaudioDekker\Keystone\Addresses;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\PendingSignIn;
use ClaudioDekker\Keystone\SignInDecision;
use Illuminate\Http\RedirectResponse;

trait StartsEnrollmentCeremonies
{
    /**
     * Get the type's running enrollment ceremony, starting it when there is none.
     *
     * @return array{ceremony: mixed, page: array<string, string>}
     */
    protected function ceremony(PendingSignIn $pending, CredentialType $type): array
    {
        $slots = Keystone::guard()->slots();
        $running = $slots->get($type->name(), Surface::ENROLLMENT->value);

        if (is_array($running)) {
            /** @var array{ceremony: mixed, page: array<string, string>} */
            return $running;
        }

        $initiation = $type->initiate(Surface::ENROLLMENT, $this->accountName($pending));
        $ceremony = ['ceremony' => $initiation?->ceremony, 'page' => $initiation->page ?? []];

        $slots->put($type->name(), Surface::ENROLLMENT->value, $ceremony, capSeconds: PendingSignIn::LIFETIME_SECONDS);

        return $ceremony;
    }

    /**
     * Get the name the account goes by in an authenticator: its first alert address, else its identifier.
     */
    protected function accountName(PendingSignIn $pending): string
    {
        $addresses = new Addresses($pending->account);

        return $addresses->recipientsOf($pending->account)[0] ?? (string) $pending->account->getAuthIdentifier();
    }

    /**
     * Get the types the account can enroll as its second factor.
     *
     * @return list<CredentialType>
     */
    protected function offer(): array
    {
        return (new SignInDecision)->enrollmentOffer(app(CredentialTypes::class));
    }

    /**
     * Get the offered type with the name.
     */
    protected function offered(string $name): ?CredentialType
    {
        $named = array_filter($this->offer(), fn (CredentialType $type) => $type->name() === $name);

        return array_values($named)[0] ?? null;
    }

    /**
     * Send a request for a type the account can't enroll back to the types it can.
     */
    protected function refuseUnofferedType(): RedirectResponse
    {
        return redirect()->route('login.enrollment');
    }
}
