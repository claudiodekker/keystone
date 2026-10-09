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

    /*
    |--------------------------------------------------------------------------
    | Context Words
    |--------------------------------------------------------------------------
    |
    | Words a new password may not contain, on top of your app's name, the
    | host of your app's URL and the local part of each email address of
    | the user setting it, ignoring case and words under 4 characters.
    |
    */

    'context_words' => [],

];
