<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Clients — who pays us (prolog-opw.1). Keys and codes are issued against
 * a client record instead of free-typed names. Deactivate, never delete:
 * keys/codes/charges keep referencing the client forever.
 */
class ClientsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'clients' => Client::query()
                ->withCount('apiKeys', 'accessCodes')
                ->orderBy('name')
                ->get()
                ->map(fn (Client $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'billing_email' => $c->billing_email,
                    'notes' => $c->notes,
                    'active' => $c->active,
                    'api_keys' => $c->api_keys_count,
                    'access_codes' => $c->access_codes_count,
                ])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:clients,name'],
            'billing_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $client = Client::create($data);

        return response()->json(['id' => $client->id, 'name' => $client->name], 201);
    }

    public function update(Request $request, Client $client): JsonResponse
    {
        $data = $request->validate([
            'name' => ['string', 'max:255', 'unique:clients,name,'.$client->id],
            'billing_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
            'active' => ['boolean'],
        ]);
        $client->update($data);

        return response()->json(['ok' => true]);
    }
}
