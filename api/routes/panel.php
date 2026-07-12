<?php

use App\Http\Controllers\Panel\AssessmentsController;
use App\Http\Controllers\Panel\AuthController;
use App\Http\Controllers\Panel\CodesController;
use App\Http\Controllers\Panel\KeysController;
use App\Http\Controllers\Panel\NormsController;
use App\Http\Controllers\Panel\PipelineController;
use App\Http\Controllers\Panel\StatsController;
use Illuminate\Support\Facades\Route;

/*
 * Control panel admin API (docs/08). Session-cookie auth (Sanctum SPA
 * flow), completely separate from the /v2 bearer-key API. Read endpoints
 * need any panel login; mutations need role:admin.
 */
Route::prefix('panel/api')->middleware('web')->group(function () {
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        Route::get('stats', [StatsController::class, 'index']);

        Route::get('keys', [KeysController::class, 'index']);
        Route::get('codes', [CodesController::class, 'index']);
        Route::get('codes/statement', [CodesController::class, 'statement']);
        Route::get('codes/statement.csv', [CodesController::class, 'statementCsv']);
        Route::get('codes/{code}', [CodesController::class, 'show']);

        Route::get('assessments', [AssessmentsController::class, 'index']);
        Route::get('assessments/{publicId}', [AssessmentsController::class, 'show']);
        Route::get('assessments/{publicId}/audit/{resultId}', [AssessmentsController::class, 'audit']);
        Route::get('assessments/{publicId}/person-timeline', [AssessmentsController::class, 'personTimeline']);

        Route::get('norms', [NormsController::class, 'index']);

        Route::get('pipeline', [PipelineController::class, 'pipeline']);
        Route::get('content/questions', [PipelineController::class, 'questions']);
        Route::get('content/translations-summary', [PipelineController::class, 'translationsSummary']);

        Route::middleware('panel.role:admin')->group(function () {
            Route::post('keys', [KeysController::class, 'store']);
            Route::patch('keys/{key}', [KeysController::class, 'update']);
            Route::post('keys/{key}/rotate', [KeysController::class, 'rotate']);

            Route::post('codes', [CodesController::class, 'store']);
            Route::patch('codes/{code}', [CodesController::class, 'update']);
            Route::post('codes/{code}/terms', [CodesController::class, 'addTerm']);
            Route::patch('terms/{term}', [CodesController::class, 'updateTerm']);
            Route::post('terms/{term}/end', [CodesController::class, 'endTerm']);

            Route::post('norms/{set:slug}/impact', [NormsController::class, 'impact']);
            Route::post('norms/{set:slug}/promote', [NormsController::class, 'promote']);
            Route::post('norms/{set:slug}/retire', [NormsController::class, 'retire']);
        });
    });
});
