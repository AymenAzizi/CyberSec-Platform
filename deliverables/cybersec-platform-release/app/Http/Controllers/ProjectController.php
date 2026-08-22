<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetRelation;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $projects = Project::query()
            ->withCount(['targets', 'scans', 'findings'])
            ->with(['scans' => fn ($q) => $q->latest()->limit(1)])
            ->when(! $user->isAdmin() && ! $user->isAuditor(),
                fn (Builder $q) => $q->where('user_id', $user->id),
            )
            ->latest()
            ->paginate(12);

        return view('projects.index', compact('projects'));
    }

    public function create()
    {
        return view('projects.create');
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        $data['user_id'] = $request->user()->id;

        if ($request->hasFile('authorization_document')) {
            $data['authorization_document'] = $request->file('authorization_document')
                ->store('authorization', 'public');
        }

        $project = Project::create(Arr::except($data, ['scope_config']) + [
            'scope_config' => $this->normaliseScope($data['scope_config'] ?? []),
        ]);

        if (! empty($data['expires_at']) && empty($project->authorized_at)) {
            $project->forceFill(['authorized_at' => now()])->save();
        }

        return redirect()->route('projects.show', $project)
            ->with('success', 'Project created.');
    }

    public function show(Project $project)
    {
        $this->authorizeProject($project);

        $project->load([
            'targets'    => fn ($q) => $q->latest(),
            'scans'      => fn ($q) => $q->latest()->limit(50),
            'findings'   => fn ($q) => $q->latest()->limit(100),
            'reports'    => fn ($q) => $q->latest(),
            'assets'     => fn ($q) => $q->limit(500),
            'alerts',
        ]);

        return view('projects.show', compact('project'));
    }

    public function edit(Project $project)
    {
        $this->authorizeProject($project);

        return view('projects.edit', compact('project'));
    }

    public function update(Request $request, Project $project)
    {
        $this->authorizeProject($project);

        $data = $this->validateData($request, $project->id);

        if ($request->hasFile('authorization_document')) {
            if ($project->authorization_document) {
                Storage::disk('public')->delete($project->authorization_document);
            }
            $data['authorization_document'] = $request->file('authorization_document')
                ->store('authorization', 'public');
        }

        $project->update(Arr::except($data, ['scope_config']) + [
            'scope_config' => $this->normaliseScope($data['scope_config'] ?? []),
        ]);

        return redirect()->route('projects.show', $project)
            ->with('success', 'Project updated.');
    }

    public function destroy(Project $project)
    {
        $this->authorizeProject($project);

        $project->delete();

        return redirect()->route('projects.index')
            ->with('success', 'Project deleted.');
    }

    public function graph(Project $project)
    {
        $this->authorizeProject($project);

        $project->load(['assets.sourceRelations']);

        return view('projects.graph', compact('project'));
    }

    public function graphData(Project $project)
    {
        $this->authorizeProject($project);

        $nodes = $project->assets()->with('sourceRelations')->get()->map(function (Asset $a) {
            return [
                'data' => [
                    'id'         => (string) $a->id,
                    'label'      => $a->label,
                    'type'       => $a->type,
                    'value'      => $a->value,
                    'risk_score' => (float) $a->risk_score,
                    'properties' => array_merge($a->metadata ?? [], $a->properties ?? []),
                ],
            ];
        });

        $edges = AssetRelation::whereIn('source_asset_id', $project->assets->pluck('id'))
            ->get()
            ->map(function (AssetRelation $r) {
                return [
                    'data' => [
                        'id'     => 'e'.$r->id,
                        'source' => (string) $r->source_asset_id,
                        'target' => (string) $r->target_asset_id,
                        'label'  => $r->type,
                    ],
                ];
            });

        return response()->json([
            'elements' => [
                'nodes' => $nodes->all(),
                'edges' => $edges->all(),
            ],
        ]);
    }

    private function authorizeProject(Project $project): void
    {
        $user = request()->user();
        if ($user->isAdmin() || $user->isAuditor()) {
            return;
        }
        abort_unless($project->user_id === $user->id, 403, 'You do not have access to this project.');
    }

    private function validateData(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'name'                       => ['required', 'string', 'max:255'],
            'description'                => ['nullable', 'string', 'max:5000'],
            'client_name'                => ['nullable', 'string', 'max:255'],
            'branding_color'             => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'status'                     => ['required', Rule::in(['draft', 'active', 'paused', 'completed', 'archived'])],
            'expires_at'                 => ['nullable', 'date'],
            'authorization_document'     => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'scope_config.allowed_domains'   => ['nullable', 'array'],
            'scope_config.allowed_domains.*' => ['nullable', 'string', 'max:255'],
            'scope_config.allowed_ips'       => ['nullable', 'array'],
            'scope_config.allowed_ips.*'     => ['nullable', 'string', 'max:64'],
            'scope_config.excluded_paths'    => ['nullable', 'string', 'max:5000'],
        ]);
    }

    private function normaliseScope(array $scope): array
    {
        $allowedDomains = array_values(array_filter(array_map('trim', $scope['allowed_domains'] ?? [])));
        $allowedIps     = array_values(array_filter(array_map('trim', $scope['allowed_ips'] ?? [])));
        $excluded       = trim($scope['excluded_paths'] ?? '');
        $excludedArray  = $excluded ? array_values(array_filter(array_map('trim', explode("\n", $excluded)))) : [];

        return [
            'allowed_domains' => $allowedDomains,
            'allowed_ips'     => $allowedIps,
            'excluded_paths'  => $excludedArray,
        ];
    }
}
