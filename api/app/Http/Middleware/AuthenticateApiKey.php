<?php

namespace App\Http\Middleware;

use App\Api\ApiException;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorization: Bearer <api_key> (docs/05). Tokens are hashed at rest —
 * lookup is by sha256. Rate limit is per key, configured on the key row.
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if ($token === null) {
            throw new ApiException(401, 'unauthenticated', 'Missing Authorization: Bearer <api_key> header.');
        }

        $key = ApiKey::findByToken($token);
        if ($key === null) {
            throw new ApiException(401, 'unauthenticated', 'Unknown or revoked API key.');
        }

        $limit = max(1, $key->rate_limit_per_minute);
        if (! RateLimiter::attempt("api-key:{$key->id}", $limit, fn () => true)) {
            throw new ApiException(429, 'rate_limited', "Rate limit of {$limit} requests/minute exceeded.", [
                'retry_after_seconds' => RateLimiter::availableIn("api-key:{$key->id}"),
            ]);
        }

        $key->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->set('apiKey', $key);

        return $next($request);
    }
}
