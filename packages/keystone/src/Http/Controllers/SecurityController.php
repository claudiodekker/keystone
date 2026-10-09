<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\Credentials;
use ClaudioDekker\Keystone\Http\PageValues\SecurityPage;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\KeystoneUser;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RecoveryCodes;
use ClaudioDekker\Keystone\Status;
use ClaudioDekker\Keystone\StepKind;
use ClaudioDekker\Keystone\SudoGate;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
abstract class SecurityController extends Controller
{
    /**
     * Get the middleware that runs before the controller's actions.
     */
    public static function middleware(): array
    {
        return [
            static::throttle(StepKind::VIEW, 'show'),
        ];
    }

    /**
     * Show the signed-in account's credentials, recovery codes and sudo, which needs no sudo of its own.
     */
    public function show(Request $request): Response|Responsable
    {
        $guard = Keystone::guard();

        if (! $guard->check()) {
            return $this->refuseGuest();
        }

        /** @var Model&KeystoneUser $account */
        $account = $guard->user();
        $held = collect((new Credentials($account))->ofAccount($account->getKey()));
        $credentialTypes = app(CredentialTypes::class);
        $listed = array_map(fn (CredentialType $type) => $type->name(), $credentialTypes->listed());
        $enrollable = array_map(fn (CredentialType $type) => $type->name(), $credentialTypes->serving(Surface::ENROLLMENT));
        $recoveryCodes = (new RecoveryCodes($account))->remaining($account->getKey());
        $status = Status::flashed($request);

        $types = array_map(fn (string $type) => [
            'type' => $type,
            'enrollable' => in_array($type, $enrollable, true),
            'credentials' => array_values($held->where('type', $type)->map(fn (array $credential) => [
                'id' => $credential['id'],
                'label' => $credential['label'],
                'addedAt' => $credential['addedAt'],
                'lastUsedAt' => $credential['lastUsedAt'],
                'disabled' => $credential['disabled'],
            ])->all()),
        ], $listed);

        $page = new SecurityPage(
            types: $types,
            leftovers: array_values($held->whereNotIn('type', $listed)->all()),
            recoveryCodes: $recoveryCodes,
            recoveryCodesLow: $recoveryCodes <= RecoveryCodes::RUNNING_LOW,
            sudoEndsAt: (new SudoGate($guard))->liveGrant()?->endsAt->toIso8601String(),
            status: $status?->label(),
            offersSignOutOthers: $status === Status::ENROLLED && ($guard->sessions($account)?->mayHaveOthers() ?? true),
        );

        return $this->sendSecurityPage($request, $page);
    }

    /**
     * Respond with the security page.
     */
    abstract protected function sendSecurityPage(Request $request, SecurityPage $page): Response|Responsable;

    /**
     * Send a guest away from a signed-in step.
     */
    protected function refuseGuest(): RedirectResponse
    {
        return redirect()->route('login');
    }
}
