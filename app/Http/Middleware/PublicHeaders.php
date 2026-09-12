<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// I9: every HTML surface serves tenant-authored content. Nothing inline, nothing from elsewhere. The public form
// is embeddable anywhere (frame-ancestors *); the dashboard is not (frame-ancestors 'none').
final class PublicHeaders
{
    public const CSP = "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; worker-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors *";

    public function handle(Request $request, Closure $next, string $frameAncestors = '*'): Response
    {
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', str_replace('frame-ancestors *', "frame-ancestors {$frameAncestors}", self::CSP));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
