<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\AccessCode;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Clients & API keys (docs/08 §2). Tokens are shown once at issue/rotate. */
class KeysController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'keys' => ApiKey::query()->with('client:id,name')->withCount('usageEvents')->orderBy('id')->get()->map(fn (ApiKey $k) => [
                'id' => $k->id,
                'name' => $k->name,
                'client' => $k->client?->name,
                'client_id' => $k->client_id,
                'key_prefix' => $k->key_prefix.'…',
                'rate_limit_per_minute' => $k->rate_limit_per_minute,
                'default_access_code' => $k->defaultAccessCode?->code,
                'webhook_url' => $k->webhook_url,
                'has_webhook_secret' => $k->webhook_secret !== null,
                'active' => $k->active,
                'last_used_at' => $k->last_used_at?->toIso8601String(),
                'usage_events' => $k->usage_events_count,
                'created_at' => $k->created_at->toIso8601String(),
            ])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'rate_limit_per_minute' => ['integer', 'min:1', 'max:10000'],
            'default_access_code' => ['nullable', 'string'],
            'webhook_url' => ['nullable', 'url'],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
        ]);

        ['token' => $token, 'attributes' => $attributes] = ApiKey::generate();
        $key = ApiKey::create([
            ...$attributes,
            'name' => $data['name'],
            'client_id' => $data['client_id'] ?? null,
            'rate_limit_per_minute' => $data['rate_limit_per_minute'] ?? 60,
            'default_access_code_id' => $this->codeId($data['default_access_code'] ?? null),
            'webhook_url' => $data['webhook_url'] ?? null,
            'webhook_secret' => $data['webhook_secret'] ?? null,
        ]);

        return response()->json(['id' => $key->id, 'token' => $token], 201);
    }

    public function update(Request $request, ApiKey $key): JsonResponse
    {
        $data = $request->validate([
            'name' => ['string', 'max:255'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'rate_limit_per_minute' => ['integer', 'min:1', 'max:10000'],
            'default_access_code' => ['nullable', 'string'],
            'webhook_url' => ['nullable', 'url'],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
            'active' => ['boolean'],
        ]);

        if (array_key_exists('default_access_code', $data)) {
            $data['default_access_code_id'] = $this->codeId($data['default_access_code']);
            unset($data['default_access_code']);
        }
        $key->update($data);

        return response()->json(['ok' => true]);
    }

    /** Rotate: new token, same row — old token stops working immediately. */
    public function rotate(ApiKey $key): JsonResponse
    {
        ['token' => $token, 'attributes' => $attributes] = ApiKey::generate();
        $key->update($attributes);

        return response()->json(['id' => $key->id, 'token' => $token]);
    }

    private function codeId(?string $code): ?int
    {
        if ($code === null || $code === '') {
            return null;
        }

        return AccessCode::query()->where('code', $code)->firstOrFail()->id;
    }
}
