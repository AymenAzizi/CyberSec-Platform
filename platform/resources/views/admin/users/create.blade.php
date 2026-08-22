@extends('layouts.app')

@section('title', 'New User')

@section('breadcrumb')
    <span class="text-gray-600">/</span>
    <a href="{{ route('admin.users.index') }}" class="hover:text-white">Users</a>
    <span class="text-gray-600">/</span>
    <span class="text-white">New</span>
@endsection

@section('content')
<div class="max-w-2xl mx-auto">
    <div class="mb-6">
        <h1 class="font-display text-2xl font-semibold text-white">New User</h1>
        <p class="text-sm text-gray-400">Create a platform account and assign a role.</p>
    </div>

    <form method="POST" action="{{ route('admin.users.store') }}" class="space-y-6">
        @csrf

        <div class="card p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="name" class="label">Full name *</label>
                    <input id="name" type="text" name="name" value="{{ old('name') }}" class="input @error('name') border-danger @enderror" required>
                    @error('name')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="email" class="label">Email *</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" class="input @error('email') border-danger @enderror" required>
                    @error('email')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="password" class="label">Password *</label>
                    <input id="password" type="password" name="password" class="input @error('password') border-danger @enderror" required>
                    @error('password')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="role" class="label">Role *</label>
                    <select id="role" name="role" class="input">
                        @foreach (['analyst','client','auditor','admin'] as $r)
                            <option value="{{ $r }}" @selected(old('role') === $r)>{{ ucfirst($r) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="quota_scans_per_day" class="label">Daily scan quota</label>
                    <input id="quota_scans_per_day" type="number" min="0" name="quota_scans_per_day" value="{{ old('quota_scans_per_day', 20) }}" class="input">
                </div>
                <div class="flex items-end">
                    <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer select-none">
                        <input type="checkbox" name="is_active" value="1" class="rounded border-white/10 bg-background text-primary focus:ring-primary" @checked(old('is_active', 1))>
                        Account active
                    </label>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('admin.users.index') }}" class="btn-outline">Cancel</a>
            <button type="submit" class="btn-primary"><span class="material-symbols-rounded text-base">save</span> Create User</button>
        </div>
    </form>
</div>
@endsection
