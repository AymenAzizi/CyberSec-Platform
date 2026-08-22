@extends('layouts.app')

@section('title', 'New Project')

@section('breadcrumb')
    <span class="text-gray-600">/</span>
    <a href="{{ route('projects.index') }}" class="hover:text-white">Projects</a>
    <span class="text-gray-600">/</span>
    <span class="text-white">New</span>
@endsection

@section('content')
<div class="max-w-4xl mx-auto">
    <div class="mb-6">
        <h1 class="font-display text-2xl font-semibold text-white">New Project</h1>
        <p class="text-sm text-gray-400">Declare the engagement scope and attach written authorization.</p>
    </div>

    <form method="POST" action="{{ route('projects.store') }}" enctype="multipart/form-data" class="space-y-6">
        @csrf

        <div class="card p-6 space-y-4">
            <h2 class="font-display text-lg text-white">Engagement</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="name" class="label">Project name *</label>
                    <input id="name" type="text" name="name" value="{{ old('name') }}"
                           class="input @error('name') border-danger @enderror" required>
                    @error('name')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="client_name" class="label">Client name</label>
                    <input id="client_name" type="text" name="client_name" value="{{ old('client_name') }}"
                           class="input">
                </div>
            </div>

            <div>
                <label for="description" class="label">Description</label>
                <textarea id="description" name="description" class="textarea" rows="3">{{ old('description') }}</textarea>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="branding_color" class="label">Branding color</label>
                    <div class="flex items-center gap-2">
                        <input id="branding_color" type="color" name="branding_color"
                               value="{{ old('branding_color', '#7c3aed') }}"
                               class="h-10 w-14 rounded border border-white/10 bg-transparent cursor-pointer">
                        <input type="text" id="branding_color_hex" value="{{ old('branding_color', '#7c3aed') }}"
                               class="input font-mono" readonly>
                    </div>
                </div>
                <div>
                    <label for="status" class="label">Status</label>
                    <select id="status" name="status" class="input">
                        @foreach (['draft','active','paused','completed','archived'] as $s)
                            <option value="{{ $s }}" @selected(old('status', 'active') === $s)>{{ ucfirst($s) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="expires_at" class="label">Authorization expires</label>
                    <input id="expires_at" type="date" name="expires_at" value="{{ old('expires_at') }}" class="input">
                </div>
            </div>

            <div>
                <label for="authorization_document" class="label">Authorization document (PDF / image)</label>
                <input id="authorization_document" type="file" name="authorization_document"
                       accept=".pdf,image/*"
                       class="block w-full text-sm text-gray-300 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-primary file:text-white file:cursor-pointer hover:file:bg-violet-700">
            </div>
        </div>

        {{-- Scope config --}}
        <div class="card p-6 space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-lg text-white">Scope</h2>
                <span class="text-xs text-gray-500">Define what's in and out of bounds</span>
            </div>

            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="label !mb-0">Allowed domains</label>
                    <button type="button" data-add-row="allowed_domains" class="btn-ghost !py-1 text-xs">
                        <span class="material-symbols-rounded text-base">add</span> Add
                    </button>
                </div>
                <div id="allowed_domains-container" class="space-y-2">
                    @foreach ((array) old('scope_config.allowed_domains', ['']) as $i => $v)
                        <div class="flex items-center gap-2">
                            <input type="text" name="scope_config[allowed_domains][]" value="{{ $v }}"
                                   class="input" placeholder="example.com">
                            <button type="button" data-remove-row class="btn-ghost !p-2 text-red-300 hover:text-red-200">
                                <span class="material-symbols-rounded text-[18px]">delete</span>
                            </button>
                        </div>
                    @endforeach
                </div>
            </div>

            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="label !mb-0">Allowed IPs / CIDRs</label>
                    <button type="button" data-add-row="allowed_ips" class="btn-ghost !py-1 text-xs">
                        <span class="material-symbols-rounded text-base">add</span> Add
                    </button>
                </div>
                <div id="allowed_ips-container" class="space-y-2">
                    @foreach ((array) old('scope_config.allowed_ips', ['']) as $i => $v)
                        <div class="flex items-center gap-2">
                            <input type="text" name="scope_config[allowed_ips][]" value="{{ $v }}"
                                   class="input" placeholder="203.0.113.0/24">
                            <button type="button" data-remove-row class="btn-ghost !p-2 text-red-300 hover:text-red-200">
                                <span class="material-symbols-rounded text-[18px]">delete</span>
                            </button>
                        </div>
                    @endforeach
                </div>
            </div>

            <div>
                <label for="excluded_paths" class="label">Excluded paths (one per line)</label>
                <textarea id="excluded_paths" name="scope_config[excluded_paths]" class="textarea font-mono text-xs" rows="4">{{ old('scope_config.excluded_paths') }}</textarea>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('projects.index') }}" class="btn-outline">Cancel</a>
            <button type="submit" class="btn-primary">
                <span class="material-symbols-rounded text-base">save</span> Create Project
            </button>
        </div>
    </form>
</div>

@push('scripts')
<script>
    // Branding color <-> hex sync
    const colorInput = document.getElementById('branding_color');
    const colorHex = document.getElementById('branding_color_hex');
    if (colorInput && colorHex) {
        colorInput.addEventListener('input', () => { colorHex.value = colorInput.value; });
    }

    // Dynamic add/remove rows
    document.querySelectorAll('[data-add-row]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const key = btn.dataset.addRow;
            const container = document.getElementById(`${key}-container`);
            if (!container) return;
            const tpl = container.querySelector('.flex.items-center.gap-2');
            if (!tpl) return;
            const clone = tpl.cloneNode(true);
            clone.querySelector('input').value = '';
            container.appendChild(clone);
        });
    });

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-remove-row]');
        if (!btn) return;
        const container = btn.closest('[id$="-container"]');
        btn.parentElement.remove();
        if (container && container.children.length === 0) {
            const key = container.id.replace('-container', '');
            const div = document.createElement('div');
            div.className = 'flex items-center gap-2';
            const ph = key === 'allowed_domains' ? 'example.com' : (key === 'allowed_ips' ? '203.0.113.0/24' : '');
            div.innerHTML = `<input type="text" name="scope_config[${key}][]" class="input" placeholder="${ph}">`;
            container.appendChild(div);
        }
    });
</script>
@endpush
@endsection
