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

    // I13: token buckets, [burst capacity, sustained refill per second]. Assumptions, generous on purpose: the goal
    // is to blunt abuse, not to ration legitimate traffic. Per IP+form: one visitor never needs 30 submits in a burst
    // or more than 1/s sustained. Per form: a viral form at 200/s sustained is well inside the broker's budget
    // (ARCHITECTURE §2). Per tenant: 2.5x the per-form limit.
    'rate_limits' => [
        'ip_form' => ['capacity' => 30, 'per_second' => 1],
        'form' => ['capacity' => 2000, 'per_second' => 200],
        'tenant' => ['capacity' => 5000, 'per_second' => 500],
    ],

    // Circuit breaker for the broker: after `failures` consecutive delivery failures fail fast for `cooldown`
    // seconds, then let one request probe.
    'breaker' => ['failures' => 5, 'cooldown_seconds' => 10],

];
