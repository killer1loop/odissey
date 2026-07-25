<?php

return [
    /*
    |--------------------------------------------------------------------------
    | First-launch setup token
    |--------------------------------------------------------------------------
    |
    | Production fails closed: this value must be configured and the matching
    | token supplied before the first administrator can be created. It is
    | ignored after setup has completed.
    |
    */
    'setup_token' => env('ODISSEY_SETUP_TOKEN'),
];
