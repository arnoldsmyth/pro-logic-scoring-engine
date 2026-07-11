<?php

use App\Http\Controllers\OpenApiController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Published API contract (docs/05): machine-readable spec + human docs UI.
Route::get('/openapi.json', [OpenApiController::class, 'document']);
Route::get('/docs', [OpenApiController::class, 'docs']);
