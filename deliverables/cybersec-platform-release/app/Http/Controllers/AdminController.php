<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class AdminController extends Controller
{
    public function auditLogs(Request $request)
    {
        $this->requireAdmin();

        $logs = AuditLog::query()
            ->with('user')
            ->when($request->input('user'), fn (Builder $q, $u) => $q->where('user_id', $u))
            ->when($request->input('action'), fn (Builder $q, $a) => $q->where('action', 'like', "%{$a}%"))
            ->when($request->input('from'), fn (Builder $q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->input('to'), fn (Builder $q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest()
            ->paginate(25);

        $users = User::orderBy('name')->get(['id', 'name']);

        return view('admin.audit-logs', compact('logs', 'users'));
    }

    public function systemHealth()
    {
        $this->requireAdmin();

        $gateway = config('services.api_gateway.url', 'http://api-gateway:8080');
        $services = $this->fetchServices($gateway);

        $dbStats  = $this->dbStats();
        $redisStats = $this->redisStats();
        $queueStats = $this->queueStats();

        return view('admin.system-health', compact('services', 'dbStats', 'redisStats', 'queueStats'));
    }

    private function fetchServices(string $gateway): array
    {
        $services = [
            ['name' => 'API Gateway',       'url' => $gateway],
            ['name' => 'Reconnaissance',    'url' => 'http://recon:5000'],
            ['name' => 'Security',          'url' => 'http://security:5001'],
            ['name' => 'OSINT',             'url' => 'http://osint:5002'],
            ['name' => 'AI',                'url' => 'http://ai:5003'],
            ['name' => 'Worker',            'url' => 'http://worker:5004'],
        ];

        foreach ($services as &$svc) {
            $start = microtime(true);
            try {
                $res = Http::timeout(2)->get($svc['url'].'/health');
                $svc['status']      = $res->ok() ? 'up' : 'down';
                $svc['response_ms'] = (int) ((microtime(true) - $start) * 1000);
            } catch (\Throwable $e) {
                $svc['status']      = 'down';
                $svc['response_ms'] = null;
            }
            $svc['last_check'] = now()->toIso8601String();
        }

        return $services;
    }

    private function dbStats(): array
    {
        try {
            $tables = DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'");
            $tableNames = array_map(fn ($t) => $t->table_name, $tables);

            $rowCounts = [];
            foreach (array_slice($tableNames, 0, 15) as $t) {
                try {
                    $rowCounts[$t] = DB::table($t)->count();
                } catch (\Throwable $e) {
                    $rowCounts[$t] = 0;
                }
            }
            arsort($rowCounts);
            $rowCounts = array_slice($rowCounts, 0, 10, true);

            try {
                $sizeRow = DB::selectOne("SELECT pg_size_pretty(pg_database_size(current_database())) AS size");
                $size = $sizeRow->size ?? '—';
            } catch (\Throwable $e) {
                $size = '—';
            }

            return [
                'size'       => $size,
                'tables'     => count($tableNames),
                'row_counts' => $rowCounts,
            ];
        } catch (\Throwable $e) {
            return ['size' => '—', 'tables' => 0, 'row_counts' => []];
        }
    }

    private function redisStats(): array
    {
        try {
            $info = Redis::connection()->info();
            return [
                'memory'         => $info['used_memory_human'] ?? '—',
                'clients'        => $info['connected_clients'] ?? 0,
                'keyspace_hits'  => $info['keyspace_hits'] ?? 0,
                'hit_ratio'      => isset($info['keyspace_hits'], $info['keyspace_misses'])
                    ? $info['keyspace_hits'] / max(1, $info['keyspace_hits'] + $info['keyspace_misses'])
                    : 0,
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function queueStats(): array
    {
        try {
            $pending = Redis::connection()->llen('queues:default');
            $failed  = Redis::connection()->zcard('failed_jobs');
        } catch (\Throwable $e) {
            $pending = 0; $failed = 0;
        }
        return [
            'pending'       => $pending,
            'failed'        => $failed,
            'recent_failed' => [],
        ];
    }

    public function usersIndex()
    {
        $this->requireAdmin();

        $users = User::withCount('scans')->with('roles')->latest()->paginate(20);
        return view('admin.users.index', compact('users'));
    }

    public function usersCreate()
    {
        $this->requireAdmin();
        return view('admin.users.create');
    }

    public function usersStore(Request $request)
    {
        $this->requireAdmin();

        $data = $request->validate([
            'name'                => ['required', 'string', 'max:255'],
            'email'               => ['required', 'email', 'unique:users,email'],
            'password'            => ['required', 'string', 'min:8'],
            'role'                => ['required', Rule::in(['admin', 'analyst', 'client', 'auditor'])],
            'quota_scans_per_day' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'is_active'           => ['nullable', 'boolean'],
        ]);

        $user = User::create([
            'name'                => $data['name'],
            'email'               => $data['email'],
            'password'            => $data['password'],
            'quota_scans_per_day' => $data['quota_scans_per_day'] ?? User::DEFAULT_QUOTA_SCANS_PER_DAY,
            'is_active'           => (bool) ($data['is_active'] ?? true),
        ]);

        $user->assignRole($data['role']);

        return redirect()->route('admin.users.index')->with('success', 'User created.');
    }

    public function usersEdit(User $user)
    {
        $this->requireAdmin();
        return view('admin.users.edit', compact('user'));
    }

    public function usersUpdate(Request $request, User $user)
    {
        $this->requireAdmin();

        $data = $request->validate([
            'name'                => ['required', 'string', 'max:255'],
            'email'               => ['required', 'email', 'unique:users,email,'.$user->id],
            'password'            => ['nullable', 'string', 'min:8'],
            'role'                => ['required', Rule::in(['admin', 'analyst', 'client', 'auditor'])],
            'quota_scans_per_day' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'is_active'           => ['nullable', 'boolean'],
        ]);

        $updateData = [
            'name'                => $data['name'],
            'email'               => $data['email'],
            'quota_scans_per_day' => $data['quota_scans_per_day'] ?? $user->quota_scans_per_day,
            'is_active'           => (bool) ($data['is_active'] ?? false),
        ];
        if (! empty($data['password'])) {
            $updateData['password'] = $data['password'];
        }
        $user->update($updateData);

        if ($data['role'] !== ($user->roles->first()?->name)) {
            $user->syncRoles([$data['role']]);
        }

        return redirect()->route('admin.users.index')->with('success', 'User updated.');
    }

    public function usersDeactivate(User $user)
    {
        $this->requireAdmin();
        abort_if($user->id === auth()->id(), 422, 'You cannot deactivate your own account.');

        $user->update(['is_active' => false]);

        return back()->with('success', 'User deactivated.');
    }

    private function requireAdmin(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403, 'Administrators only.');
    }
}
