<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Window Steps
    |--------------------------------------------------------------------------
    |
    | How many 30-second steps either side of now a code may come from,
    | so a phone whose clock drifts still signs in. Each step allows 2
    | more codes per guess, and 0 accepts the current step's code only.
    |
    */

    'window_steps' => 1,

];
