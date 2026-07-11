<?php

namespace App\Http\Middleware;

use App\Api\ApiException;
use App\Models\ApiKey;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key support on mutating endpoints (docs/05): same key + same
 * payload replays the stored response; same key + different payload is a
 * 409 (client bug, not a retry).
 */
class IdempotentRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $idemKey = $request->header('Idempotency-Key');
        if ($idemKey === null || $idemKey === '') {
            return $next($request);
        }

        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('apiKey');
        $requestHash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());

        $existing = IdempotencyKey::query()
            ->where('api_key_id', $apiKey->id)
            ->where('idempotency_key', $idemKey)
            ->first();

        if ($existing !== null) {
            if ($existing->request_hash !== $requestHash) {
                throw new ApiException(409, 'idempotency_conflict', 'Idempotency-Key was already used with a different request payload.');
            }

            return response()->json($existing->response_body, $existing->response_status)
                ->header('Idempotency-Replayed', 'true');
        }

        $response = $next($request);

        if ($response instanceof JsonResponse && $response->getStatusCode() < 500) {
            try {
                IdempotencyKey::create([
                    'api_key_id' => $apiKey->id,
                    'idempotency_key' => $idemKey,
                    'request_hash' => $requestHash,
                    'response_status' => $response->getStatusCode(),
                    'response_body' => $response->getData(true),
                    'created_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Concurrent duplicate: first writer wins; this response is identical.
            }
        }

        return $response;
    }
}
