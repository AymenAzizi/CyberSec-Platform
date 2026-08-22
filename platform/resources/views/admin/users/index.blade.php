@extends('layouts.app')

@section('title', 'Users')

@section('breadcrumb')
    <span class="text-gray-600">/</span>
    <a href="{{ route('admin.users.index') }}" class="hover:text-white">Admin</a>
    <span class="text-gray-600">/</span>
    <span class="text-white">Users</span>
@endsection

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h1 class="font-display text-2xl font-semibold text-white">Users</h1>
            <p class="text-sm text-gray-400">{{ $users->total() }} user{{ $users->total() === 1 ? '' : 's' }} · {{ $users->where('is_active', true)->count() }} active</p>
        </div>
        <a href="{{ route('admin.users.create') }}" class="btn-primary">
            <span class="material-symbols-rounded text-base">person_add</span> New User
        </a>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto" data-searchable>
            <table class="table">
                <thead>
                    <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last login</th><th>Scans</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr data-search-item="{{ $user->name }} {{ $user->email }}">
                            <td class="text-sm text-white">{{ $user->name }}</td>
                            <td class="text-xs font-mono">{{ $user->email }}</td>
                            <td>
                                @php $role = collect($user->roles)->first()?->name ?? 'user'; @endphp
                                <span class="badge-violet">{{ ucfirst($role) }}</span>
                            </td>
                            <td>
                                @if ($user->is_active)
                                    <span class="badge-success"><span class="material-symbols-rounded text-[12px]">check_circle</span> Active</span>
                                @else
                                    <span class="badge-neutral"><span class="material-symbols-rounded text-[12px]">block</span> Disabled</span>
                                @endif
                            </td>
                            <td class="text-xs">{{ $user->last_login_at?->diffForHumans() ?? 'never' }}</td>
                            <td class="text-xs">{{ $user->scans_count ?? $user->scans->count() }}</td>
                            <td>
                                <div class="flex items-center gap-1">
                                    <a href="{{ route('admin.users.edit', $user) }}" class="btn-ghost !p-1.5" title="Edit"><span class="material-symbols-rounded text-[18px]">edit</span></a>
                                    @if ($user->is_active && $user->id !== auth()->id())
                                        <form method="POST" action="{{ route('admin.users.deactivate', $user) }}" data-confirm="Deactivate user {{ $user->name }}?">
                                            @csrf @method('PATCH')
                                            <button type="submit" class="btn-ghost !p-1.5 text-red-300 hover:text-red-200" title="Deactivate"><span class="material-symbols-rounded text-[18px]">block</span></button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-gray-500 py-6">No users.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-4 py-3 border-t border-white/5">{{ $users->withQueryString()->links() }}</div>
    </div>
</div>
@endsection
