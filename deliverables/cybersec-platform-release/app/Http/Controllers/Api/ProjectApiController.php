<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;

/**
 * Public API — exposes project-level findings to CI/CD integrations.
 *
 * Protected by `auth:sanctum`. Stub implementation:
 * route-list scaffolding only.
 */
class ProjectApiController extends Controller
{
    public function findings(Project $project): JsonResponse
    {
        return $this->stub('findings', ['project_id' => $project->id]);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function stub(string $method, array $context = []): JsonResponse
    {
        return response()->json(array_merge([
            'message'    => 'Api\\ProjectApiController — stub implementation',
            'method'     => $method,
            'controller' => self::class,
            'todo'       => 'BUILD-6: implement /api/projects/{project}/findings.',
        ], $context));
    }
}
