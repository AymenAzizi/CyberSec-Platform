<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Scan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public API for CI/CD integrations — launch scans and inspect results.
 *
 * Protected by `auth:sanctum`. Stub implementation:
 * route-list scaffolding only.
 */
class ScanApiController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        return $this->stub('store');
    }

    public function show(Scan $scan): JsonResponse
    {
        return $this->stub('show', ['scan_id' => $scan->id]);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function stub(string $method, array $context = []): JsonResponse
    {
        return response()->json(array_merge([
            'message'    => 'Api\\ScanApiController — stub implementation',
            'method'     => $method,
            'controller' => self::class,
            'todo'       => 'BUILD-6: implement /api/scans store + show (CI/CD).',
        ], $context));
    }
}
