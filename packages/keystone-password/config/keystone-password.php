<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Minimum Length
    |--------------------------------------------------------------------------
    |
    | The fewest characters a new password may hold. Null follows the
    | second-factor mandate: 8 while keystone.require_second_factor
    | is on, or 15 while a password alone may sign an account in.
    |
    */

    'min_length' => null,

];
