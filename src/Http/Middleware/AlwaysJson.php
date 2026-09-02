<?php

namespace Goldnead\ClientRooms\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * These routes answer JSON, whatever the caller asked for.
 *
 * Without this, Laravel decides from the `Accept` header: a front end that
 * posts a `FormData` and forgets the header gets a 302 back to the previous
 * page instead of a 422 with the field errors, and the failed upload looks to
 * the client as though it worked. That is a bug to find at three in the
 * afternoon with a client on the phone, and one header is not worth it.
 */
class AlwaysJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
