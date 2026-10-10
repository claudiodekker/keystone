<?php

namespace ClaudioDekker\Keystone\Actions;

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\KeystoneUser;
use Illuminate\Database\Eloquent\Model;

/**
 * @api
 */
class CreateAccount
{
    /**
     * Get the rules for the fields the registration's finish takes besides the credential.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255']];
    }

    /**
     * Create the account's row in the app's users table from the validated fields.
     *
     * @param  array<string, mixed>  $profile
     * @return Model&KeystoneUser
     */
    public function handle(array $profile): Model
    {
        $account = Keystone::guard()->userModel()->newInstance();

        $account->forceFill(['name' => $profile['name']])->save();

        return $account;
    }
}
