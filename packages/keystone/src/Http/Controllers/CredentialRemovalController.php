<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\AccountChange;
use ClaudioDekker\Keystone\AccountChanges;
use ClaudioDekker\Keystone\Credentials;
use ClaudioDekker\Keystone\Http\PageValues\CredentialRemovalPage;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\KeystoneUser;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Status;
use ClaudioDekker\Keystone\StepKind;
use ClaudioDekker\Keystone\SudoGate;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
abstract class CredentialRemovalController extends Controller
{
    /**
     * Get the middleware that runs before the controller's actions.
     */
    public static function middleware(): array
    {
        return [
            static::throttle(StepKind::VIEW, 'show'),
            static::throttle(StepKind::CHANGE, 'destroy'),
            static::sudo('show', 'destroy'),
        ];
    }

    /**
     * Show the signed-in account's credential, for the user to confirm its removal.
     */
    public function show(Request $request, string $credential): Response|Responsable
    {
        (new SudoGate(Keystone::guard()))->enforce($request);

        /** @var Model&KeystoneUser $account */
        $account = Keystone::guard()->user();
        $id = $this->credentialId($credential);
        $held = $id === null ? null : (new Credentials($account))->find($id, $account->getKey());

        if ($held === null) {
            return $this->refuseUnknownCredential($request);
        }

        $listed = array_map(fn (CredentialType $type) => $type->name(), app(CredentialTypes::class)->listed());

        $page = new CredentialRemovalPage(
            id: $held['id'],
            type: $held['type'],
            label: $held['label'],
            listed: in_array($held['type'], $listed, true),
        );

        return $this->sendRemovalPage($request, $page);
    }

    /**
     * Remove the signed-in account's credential, ending its other sessions.
     */
    public function destroy(Request $request, string $credential): Response|Responsable
    {
        (new SudoGate(Keystone::guard()))->enforce($request);

        $guard = Keystone::guard();
        /** @var Model&KeystoneUser $account */
        $account = $guard->user();
        $id = $this->credentialId($credential);

        $removed = $id !== null && (new AccountChanges($guard))->change($account, fn (AccountChange $change) => $change->removeCredential($id));

        if (! $removed) {
            return $this->refuseUnknownCredential($request);
        }

        Status::CREDENTIAL_REMOVED->flash($request);

        return $this->sendCredentialRemoved($request);
    }

    /**
     * Respond with the page confirming the credential's removal.
     */
    abstract protected function sendRemovalPage(Request $request, CredentialRemovalPage $page): Response|Responsable;

    /**
     * Respond to a removed credential.
     */
    abstract protected function sendCredentialRemoved(Request $request): Response|Responsable;

    /**
     * Respond to a credential the account doesn't hold.
     */
    abstract protected function sendCredentialNotFound(Request $request): Response|Responsable;

    /**
     * Send the user back from a credential the account doesn't hold, saying so.
     */
    protected function refuseUnknownCredential(Request $request): Response|Responsable
    {
        Status::CREDENTIAL_NOT_FOUND->flash($request);

        return $this->sendCredentialNotFound($request);
    }

    /**
     * Parse the credential id from the URL, or null when it can't name a credential.
     */
    protected function credentialId(string $credential): ?int
    {
        $id = filter_var($credential, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }
}
