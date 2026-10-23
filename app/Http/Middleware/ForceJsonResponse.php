<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets `Accept: application/json` on every request that enters the API
 * middleware stack.
 *
 * Effect: Laravel's default behaviours (validation, auth, route-not-found)
 * will produce JSON error pages instead of HTML — even if the client
 * forgot to set the header.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');
        return $next($request);
    }
}