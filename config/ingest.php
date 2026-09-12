<?php

return [

    // I13: HMAC key for render tokens. Dedicated, so rotating it never touches APP_KEY (sessions, encryption).
    'render_token_key' => env('RENDER_TOKEN_KEY', ''),

    // Pseudonymises visitor IPs in submission meta. Separate from the token key so token rotation doesn't break
    // correlation of stored submissions.
    'ip_hash_key' => env('IP_HASH_KEY', ''),

    // I13: a token is accepted from min_fill_seconds after render (bots submit instantly) until max_age.
    'token' => ['min_fill_seconds' => 2, 'max_age_seconds' => 86400],

    // I3: a superseded version keeps accepting submissions for this long after the next one was published.
    'version_grace_seconds' => 86400,

    // I13: token buckets, [burst capacity, sustained refill per second]. Per IP+form is tight: one visitor never
    // legitimately bursts. Per form and per tenant are abuse ceilings, not traffic shapers: the brief's core scenario
    // is a legitimate burst from a high-traffic embed, and the topic is what absorbs it (ARCHITECTURE §2). They sit
    // above anything a single form has been measured to need and below what one broker node was measured to take.
    // Per tenant is 2.5x per form. All assumptions.
    'rate_limits' => [
        'ip_form' => ['capacity' => 30, 'per_second' => 1],
        'form' => ['capacity' => 5000, 'per_second' => 2000],
        'tenant' => ['capacity' => 12500, 'per_second' => 5000],
    ],

    // Circuit breaker for the broker: after `failures` consecutive delivery failures fail fast for `cooldown`
    // seconds, then let one request probe.
    'breaker' => ['failures' => 5, 'cooldown_seconds' => 10],

];
