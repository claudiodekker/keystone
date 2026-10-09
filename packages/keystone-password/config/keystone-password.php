<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Minimum Length
    |--------------------------------------------------------------------------
    |
    | The fewest characters a new password may have, by whether your app
    | sets keystone.require_second_factor. A password that may be the
    | only factor needs 15 (NIST SP 800-63B). None may go below 8.
    |
    */

    'min_length' => [
        'second_factor_required' => 8,
        'second_factor_optional' => 15,
    ],

];
