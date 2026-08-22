<?php

use App\Http\Controllers\Api\ProjectApiController;
use App\Http\Controllers\Api\ReportApiController;
use App\Http\Controllers\Api\ScanApiController;
use App\Http\Controllers\Api\ScanCallbackController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — PFE CyberSec Platform
|--------------------------------------------------------------------------
|
| Two families of routes live here:
|
|   1. Worker callbacks — used by the Python worker / microservices to
|      fetch the next queued scan, post back results, append findings,
|      and update the knowledge graph. Protected by auth:sanctum with
|      a bearer-token fallback (WORKER_CALLBACK_TOKEN) when called by
|      the worker.
|
|   2. Public API — used by CI/CD integrations to launch scans, retrieve
|      findings, and download reports programmatically. Protected by
|      auth:sanctum.
|
| A public health-check endpoint is exposed at /api/health.
|
*/

// ---------------------------------------------------------------------------
// Authenticated API (Sanctum tokens)
// ---------------------------------------------------------------------------
Route::prefix('api')->middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $r) => $r->user());

    // Worker callbacks
    Route::get('/queue/next', [ScanCallbackController::class, 'next']);
    Route::post('/scans/{scan}/callback', [ScanCallbackController::class, 'update']);
    Route::post('/scans/{scan}/findings', [ScanCallbackController::class, 'addFindings']);
    Route::post('/scans/{scan}/graph', [ScanCallbackController::class, 'updateGraph']);

    // Public API for CI/CD integration
    Route::post('/scans', [ScanApiController::class, 'store']);
    Route::get('/scans/{scan}', [ScanApiController::class, 'show']);
    Route::get('/projects/{project}/findings', [ProjectApiController::class, 'findings']);
    Route::get('/reports/{report}/download', [ReportApiController::class, 'download']);
});

// ---------------------------------------------------------------------------
// Health check endpoint (public)
// ---------------------------------------------------------------------------
Route::get('/api/health', fn () => response()->json(['status' => 'ok', 'time' => now()]));
