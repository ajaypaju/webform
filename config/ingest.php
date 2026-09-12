<?php

return [

    // I13: HMAC key for render tokens. Dedicated, so rotating it never touches APP_KEY (sessions, encryption).
    'render_token_key' => env('RENDER_TOKEN_KEY', ''),

];
