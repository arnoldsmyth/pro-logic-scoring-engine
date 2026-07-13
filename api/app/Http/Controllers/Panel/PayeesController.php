<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Payee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payees — who we pay via payout schedules (prolog-opw.1). Separate from
 * clients; optionally linked to one when the same party is both.
 * Deactivate, never delete: payout terms and the payouts ledger keep
 * referencing the payee forever.
 */
class PayeesController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'payees' => Payee::query()
                ->with('client:id,name')
                ->withCount('payoutTerms')
                ->orderBy('name')
                ->get()
                ->map(fn (Payee $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'email' => $p->email,
                    'notes' => $p->notes,
                    'active' => $p->active,
                    'client' => $p->client?->name,
                    'client_id' => $p->client_id,
                    'payout_terms' => $p->payout_terms_count,
                ])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:payees,name'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
            'client_id' => ['nullable', 'exists:clients,id'],
        ]);

        $payee = Payee::create($data);

        return response()->json(['id' => $payee->id, 'name' => $payee->name], 201);
    }

    public function update(Request $request, Payee $payee): JsonResponse
    {
        $data = $request->validate([
            'name' => ['string', 'max:255', 'unique:payees,name,'.$payee->id],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'active' => ['boolean'],
        ]);
        $payee->update($data);

        return response()->json(['ok' => true]);
    }
}
