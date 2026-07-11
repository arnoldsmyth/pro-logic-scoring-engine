<?php

use App\Http\Controllers\Api\AssessmentController;
use App\Http\Controllers\Api\ReferenceController;
use App\Http\Controllers\Api\ScoreController;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\IdempotentRequests;
use Illuminate\Support\Facades\Route;

// docs/05 — all endpoints authenticated; mutating endpoints honor
// Idempotency-Key.
Route::prefix('v2')->middleware([AuthenticateApiKey::class])->group(function () {
    Route::middleware([IdempotentRequests::class])->group(function () {
        Route::post('assessments', [AssessmentController::class, 'store']);
        Route::put('assessments/{id}/tools/{tool}', [AssessmentController::class, 'submitTool']);
        Route::post('assessments/{id}/score', [ScoreController::class, 'scoreAssessment']);
        Route::post('score', [ScoreController::class, 'oneShot']);
    });

    Route::get('assessments/{id}', [AssessmentController::class, 'show']);
    Route::get('assessments/{id}/results', [AssessmentController::class, 'results']);
    Route::get('assessments/{id}/results/audit', [AssessmentController::class, 'audit']);

    Route::get('reference/languages', [ReferenceController::class, 'languages']);
    Route::get('reference/questions', [ReferenceController::class, 'questions']);
    Route::get('reference/translations', [ReferenceController::class, 'translations']);
    Route::get('reference/scopes', [ReferenceController::class, 'scopes']);
    Route::get('reference/norm-sets', [ReferenceController::class, 'normSets']);
});
