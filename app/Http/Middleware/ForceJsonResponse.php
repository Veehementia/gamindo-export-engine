<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Forza il content negotiation a JSON: così gli errori di validazione e le
 * eccezioni vengono sempre serializzati come JSON (è un'API, non un sito web).
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next)
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
