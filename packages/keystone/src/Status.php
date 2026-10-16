<?php

namespace ClaudioDekker\Keystone;

use Illuminate\Support\Facades\Lang;

/**
 * A status core tells the user, translated under keystone:: before it reaches a page or a hook.
 *
 * @api
 */
enum Status: string
{
    case CREDENTIALS_REJECTED = 'credentials-rejected';

    /**
     * Get the status's message in the app's locale.
     */
    public function label(): string
    {
        return Lang::get('keystone::messages.'.$this->value);
    }
}
