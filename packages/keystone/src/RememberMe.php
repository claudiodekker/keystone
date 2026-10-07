<?php

namespace ClaudioDekker\Keystone;

use Illuminate\Http\Request;

/**
 * @internal
 */
enum RememberMe: string
{
    case ASKED = 'asked';
    case NOT_ASKED = 'not-asked';

    /**
     * The input field a sign-in ticks to be remembered on its device.
     */
    public const string FIELD = 'remember';

    /**
     * Get whether the submitted sign-in asked to be remembered on its device.
     */
    public static function fromRequest(Request $request): self
    {
        return $request->boolean(self::FIELD) ? self::ASKED : self::NOT_ASKED;
    }
}
