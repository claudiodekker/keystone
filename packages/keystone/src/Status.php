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
    case SIGNED_OUT = 'signed-out';
    case THROTTLED = 'throttled';

    /**
     * Get the status's message in the app's locale, with its placeholders replaced.
     *
     * @param  array<string, int|string>  $replace
     */
    public function label(array $replace = []): string
    {
        return Lang::get('keystone::messages.'.$this->value, $replace);
    }
}
