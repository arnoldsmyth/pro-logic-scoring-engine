<?php

use App\Http\Middleware\EnsurePanelRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '', // routes/api.php already namespaces under /v2 (docs/05)
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            require __DIR__.'/../routes/panel.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['panel.role' => EnsurePanelRole::class]);
        $middleware->redirectGuestsTo(fn () => abort(401, 'Unauthenticated.'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // ApiException renders itself; align Laravel's field validation with
        // the same {error: {code, message, details}} envelope.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('v2/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'invalid_request',
                        'message' => $e->getMessage(),
                        'details' => ['fields' => $e->errors()],
                    ],
                ], 422);
            }
        });
    })->create();
