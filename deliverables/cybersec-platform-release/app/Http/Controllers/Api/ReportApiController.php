<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Report;
use Illuminate\Http\JsonResponse;

/**
 * Public API — streams a generated report to a CI/CD client.
 *
 * Protected by `auth:sanctum`. Stub implementation:
 * route-list scaffolding only.
 */
class ReportApiController extends Controller
{
    public function download(Report $report): JsonResponse
    {
        return $this->stub('download', ['report_id' => $report->id]);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function stub(string $method, array $context = []): JsonResponse
    {
        return response()->json(array_merge([
            'message'    => 'Api\\ReportApiController — stub implementation',
            'method'     => $method,
            'controller' => self::class,
            'todo'       => 'BUILD-6: stream signed PDFs over the API.',
        ], $context));
    }
}
