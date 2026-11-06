<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public Shop
    |--------------------------------------------------------------------------
    |
    | Off unless explicitly turned on, and any value that is not clearly "on"
    | keeps the shop closed. Stripe webhooks, scheduled reconciliation and the
    | admin pages keep working while closed, so orders already paid still settle.
    |
    */

    'enabled' => filter_var(env('EC_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Tax Date Timezone
    |--------------------------------------------------------------------------
    |
    | Tax rates start and end on dates in this timezone, so a rate change takes
    | effect at midnight there. Fixed here rather than set per environment,
    | because the tax charged must not depend on a server setting.
    |
    */

    'tax_timezone' => 'Asia/Tokyo',

];
