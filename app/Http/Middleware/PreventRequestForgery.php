<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as Middleware;

final class PreventRequestForgery extends Middleware
{
    // WHY: the dashboard sends the token from its JSON data block (X-CSRF-TOKEN), so the JS-readable XSRF-TOKEN
    // cookie Laravel adds by default is unused surface; the framework has no config key for it, only this flag.
    protected $addHttpCookie = false;
}
