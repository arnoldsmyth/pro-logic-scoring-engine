<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel roles (docs/08): viewer sees everything, admin changes things.
 * Mutating panel endpoints are gated with role:admin.
 */
class EnsurePanelRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();
        if ($user === null || ($role === 'admin' && $user->role !== 'admin')) {
            return response()->json(['error' => ['code' => 'forbidden', 'message' => 'This action needs the admin role.']], 403);
        }

        return $next($request);
    }
}
