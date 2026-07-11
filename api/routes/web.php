<?php

use App\Http\Controllers\OpenApiController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Published API contract (docs/05): machine-readable spec + human docs UI.
Route::get('/openapi.json', [OpenApiController::class, 'document']);
Route::get('/docs', [OpenApiController::class, 'docs']);

// Control panel SPA (docs/08). Assets build into public/panel-assets (a
// real directory named differently from the /panel URL prefix on purpose:
// PHP's built-in dev server mis-resolves SCRIPT_NAME when a URL prefix
// collides with an existing directory). /panel/api/* never reaches this —
// those routes are registered first.
Route::get('/panel/{any?}', function () {
    $index = public_path('panel-assets/index.html');
    abort_unless(is_file($index), 404, 'Panel not built — run `npm run build` in panel/.');

    return response()->file($index);
})->where('any', '^(?!api/).*');
