<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Admin-only CRUD for platform users.
 *
 * All routes are protected by the `role:admin` middleware (registered in
 * routes/web.php). User **deletion** is a soft action: the account is
 * marked `is_active=false` rather than physically removed, so the audit
 * trail keeps referential integrity with historical scans and findings.
 */
class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::with('roles')
            ->withCount(['scans', 'projects'])
            ->when($request->input('role'), fn ($q, $role) => $q->role($role))
            ->when($request->filled('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->latest()
            ->paginate(20);

        $roles = Role::orderBy('name')->pluck('name');

        return view('admin.users.index', compact('users', 'roles'));
    }

    public function create()
    {
        $roles = Role::orderBy('name')->pluck('name');

        return view('admin.users.create', compact('roles'));
    }

    public function store(StoreUserRequest $request)
    {
        $data = $request->validatedUserData();

        $user = DB::transaction(function () use ($data, $request) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'is_active' => $data['is_active'] ?? true,
                'quota_scans_per_day' => $data['quota_scans_per_day'] ?? User::DEFAULT_QUOTA_SCANS_PER_DAY,
            ]);

            $role = Role::firstOrCreate(['name' => $request->input('role'), 'guard_name' => 'web']);
            $user->assignRole($role);

            return $user;
        });

        AuditLogger::log($request->user(), 'user.created', $user, [
            'role' => $request->input('role'),
        ]);

        return redirect()->route('admin.users.index')
            ->with('status', __('User created.'));
    }

    public function edit(User $user)
    {
        $user->load('roles');
        $roles = Role::orderBy('name')->pluck('name');

        return view('admin.users.edit', compact('user', 'roles'));
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $data = $request->validatedUserData();

        $user->fill($data)->save();

        if ($request->safe()->has('role')) {
            $role = Role::firstOrCreate(['name' => $request->safe()->input('role'), 'guard_name' => 'web']);
            $user->syncRoles([$role->name]);
        }

        AuditLogger::log($request->user(), 'user.updated', $user, [
            'changed' => array_keys($data),
            'role' => $request->safe()->input('role'),
        ]);

        return redirect()->route('admin.users.index')
            ->with('status', __('User updated.'));
    }

    /**
     * Deactivate (soft delete) a user. Hard deletes would orphan historical
     * audit-log + scan records that reference the user_id.
     */
    public function destroy(Request $request, User $user)
    {
        abort_if($user->id === $request->user()->id, 403, 'You cannot deactivate your own account.');

        $user->is_active = false;
        $user->save();

        AuditLogger::log($request->user(), 'user.deactivated', $user);

        return redirect()->route('admin.users.index')
            ->with('status', __('User deactivated.'));
    }
}
