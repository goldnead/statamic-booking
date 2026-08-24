<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Endpoints
    |--------------------------------------------------------------------------
    |
    | One entry per booking funnel. A free consultation and a paid lesson are
    | different things: different Cal.com event types, different secrets, and
    | usually different consequences. They are configuration here rather than
    | separate controllers, because two controllers doing the same job is the
    | copy this family has already paid for once.
    |
    | The handle appears in the URL and in every stored row, so it is part of
    | your setup the moment the first booking arrives — do not rename it after.
    |
    |     POST /!/statamic-booking/{handle}
    |
    | Each endpoint needs its own `secret`, set in the environment and matching
    | the one entered in Cal.com. **Without a secret the endpoint refuses every
    | request**: an unverified booking webhook is an open write endpoint, and
    | failing closed is the only defensible default.
    |
    | Empty on purpose. An addon cannot know which funnels a site runs.
    |
    */

    'endpoints' => [
        // 'beratung' => [
        //     'secret' => env('BOOKING_SECRET_BERATUNG'),
        //     'label' => 'Kostenloses Erstgespräch',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Signature
    |--------------------------------------------------------------------------
    |
    | Cal.com signs the raw body with HMAC-SHA256 and sends the digest in
    | `X-Cal-Signature-256`. Both are configurable because a second provider
    | with the same shape should not need a second addon.
    |
    | `tolerance_seconds` bounds replay: a signature says nothing about *when*
    | it was made, so a captured delivery stays valid forever unless something
    | else limits it. Null switches the check off, which is only reasonable for
    | a provider that sends no timestamp.
    |
    */

    'signature' => [
        'header' => 'X-Cal-Signature-256',
        'algorithm' => 'sha256',
        'timestamp_header' => null,
        'tolerance_seconds' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limit
    |--------------------------------------------------------------------------
    |
    | Per minute, per IP. The endpoint writes rows, so it needs a brake even
    | though a valid signature is required to write anything.
    |
    */

    'rate_limit' => 60,

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | `php please booking:prune` deletes bookings whose appointment is older
    | than this. A booking carries a name and an address, so keeping every one
    | forever is the opposite of data minimisation. Null keeps everything.
    |
    */

    'keep_days' => 730,
];
