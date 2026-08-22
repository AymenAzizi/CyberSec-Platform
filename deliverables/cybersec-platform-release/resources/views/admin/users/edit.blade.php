@extends('layouts.app')

@section('title', 'Edit · ' . $user->name)

@section('breadcrumb')
    <span class="text-gray-600">/</span>
    <a href="{{ route('admin.users.index') }}" class="hover:text-white">Users</a>
    <span class="text-gray-600">/</span>
    <span class="text-white">{{ $user->name }}</span>
@endsection

@section('content')
<div class="max-w-2xl mx-auto">
    <div class="mb-6">
        <h1 class="font-display text-2xl font-semibold text-white">Edit User</h1>
        <p class="text-sm text-gray-400">Update profile, role and quota.</p>
    </div>

    <form method="POST" action="{{ route('admin.users.update', $user) }}" class="space-y-6">
        @csrf @method('PUT')

        <div class="card p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="name" class="label">Full name *</label>
                    <input id="name" type="text" name="name" value="{{ old('name', $user->name) }}" class="input @error('name') border-danger @enderror" required>
                    @error('name')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="email" class="label">Email *</label>
                    <input id="email" type="email" name="email" value="{{ old('email', $user->email) }}" class="input @error('email') border-danger @enderror" required>
                    @error('email')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="password" class="label">New password</label>
                    <input id="password" type="password" name="password" class="input @error('password') border-danger @enderror" placeholder="Leave blank to keep current">
                    @error('password')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="role" class="label">Role *</label>
                    <select id="role" name="role" class="input">
                        @foreach (['analyst','client','auditor','admin'] as $r)
                            <option value="{{ $r }}" @selected(old('role', collect($user->roles)->first()?->name) === $r)>{{ ucfirst($r) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="quota_scans_per_day" class="label">Daily scan quota</label>
                    <input id="quota_scans_per_day" type="number" min="0" name="quota_scans_per_day" value="{{ old('quota_scans_per_day', $user->quota_scans_per_day) }}" class="input">
                </div>
                <div class="flex items-end">
                    <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer select-none">
                        <input type="checkbox" name="is_active" value="1" class="rounded border-white/10 bg-background text-primary focus:ring-primary" @checked(old('is_active', $user->is_active))>
                        Account active
                    </label>
                </div>
            </div>

            <div class="text-xs text-gray-500 border-t border-white/5 pt-3">
                Last login: {{ $user->last_login_at?->toDateTimeString() ?? 'never' }}
                @if ($user->last_login_ip) · IP: <code>{{ $user->last_login_ip }}</code> @endif
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('admin.users.index') }}" class="btn-outline">Cancel</a>
            <button type="submit" class="btn-primary"><span class="material-symbols-rounded text-base">save</span> Save Changes</button>
        </div>
    </form>
</div>
@endsection
