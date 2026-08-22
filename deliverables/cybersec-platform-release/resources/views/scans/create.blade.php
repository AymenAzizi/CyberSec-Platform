@extends('layouts.app')

@section('title', 'New Scan')

@section('breadcrumb')
    <span class="text-gray-600">/</span>
    <a href="{{ route('scans.index') }}" class="hover:text-white">Scans</a>
    <span class="text-gray-600">/</span>
    <span class="text-white">New</span>
@endsection

@section('content')
<div class="max-w-5xl mx-auto">
    <div class="mb-6">
        <h1 class="font-display text-2xl font-semibold text-white">Launch Scan</h1>
        <p class="text-sm text-gray-400">Select a target and a scan type. Profiles enforce rate limits and jitter.</p>
    </div>

    <form method="POST" action="{{ route('scans.store') }}" class="space-y-6" id="scan-form">
        @csrf

        <div class="card p-6 space-y-4">
            <h2 class="font-display text-lg text-white">Target</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="project_id" class="label">Project *</label>
                    <select id="project_id" name="project_id" class="input" required>
                        <option value="">Choose a project…</option>
                        @foreach ($projects as $p)
                            <option value="{{ $p->id }}" @selected(old('project_id', request('project')) == $p->id)>{{ $p->name }}</option>
                        @endforeach
                    </select>
                    @error('project_id')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="target_id" class="label">Target *</label>
                    <select id="target_id" name="target_id" class="input" required>
                        <option value="">Choose a project first…</option>
                    </select>
                    <div id="target-auth-status" class="text-xs text-gray-500 mt-1"></div>
                    @error('target_id')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        {{-- Scan types --}}
        <div class="card p-6 space-y-4">
            <h2 class="font-display text-lg text-white">Scan Type</h2>

            @php
                $groups = [
                    'Reconnaissance' => \App\Models\Scan::RECON_TYPES,
                    'Security Testing' => \App\Models\Scan::SECURITY_TYPES,
                    'Sandbox Testing' => \App\Models\Scan::SANDBOX_TYPES,
                ];
                $descriptions = [
                    'nmap' => 'Port & service discovery',
                    'nuclei' => 'Vulnerability templates',
                    'gobuster' => 'Directory/file brute force',
                    'subfinder' => 'Passive subdomain enumeration',
                    'wpscan' => 'WordPress enumeration',
                    'attack_detect' => 'Headers, methods, sensitive paths',
                    'injection_full' => 'SQL + Command + XSS combined',
                    'injection_sql' => 'SQL injection payloads',
                    'injection_cmd' => 'OS command injection',
                    'injection_xss' => 'Cross-site scripting payloads',
                    'waf_detect' => 'WAF fingerprinting',
                    'prevention_check' => 'Defense verification',
                    'sandbox_full' => 'Full sandboxed exploit suite',
                    'sandbox_sqli' => 'Sandboxed SQLi validation',
                    'sandbox_cmdi' => 'Sandboxed command injection',
                    'sandbox_xss' => 'Sandboxed XSS validation',
                    'osint' => 'Passive OSINT collection',
                ];
            @endphp

            @foreach ($groups as $label => $types)
                <div>
                    <div class="text-xs uppercase tracking-wider text-gray-500 mb-2">{{ $label }}</div>
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2">
                        @foreach ($types as $type)
                            <label class="relative">
                                <input type="radio" name="type" value="{{ $type }}"
                                       class="peer absolute opacity-0"
                                       @checked(old('type') === $type) required>
                                <div class="card !rounded-lg p-3 cursor-pointer hover:border-primary/40 peer-checked:border-primary peer-checked:bg-primary/10 transition-all">
                                    <div class="text-sm font-mono text-white">{{ $type }}</div>
                                    <div class="text-[11px] text-gray-500 mt-0.5">{{ $descriptions[$type] ?? '' }}</div>
                                </div>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
            @error('type')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>

        {{-- Profiles --}}
        <div class="card p-6 space-y-4">
            <h2 class="font-display text-lg text-white">Profile</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                @php
                    $profiles = [
                        'silent' => ['label' => 'Silent', 'desc' => 'IDS-evasion · 1–2 req/s, 500–2000ms jitter, 1200s timeout', 'icon' => 'visibility_off', 'color' => 'text-cyan-300'],
                        'balanced' => ['label' => 'Balanced', 'desc' => 'Default · 8 req/s, 100–500ms jitter, 600s timeout', 'icon' => 'balance', 'color' => 'text-violet-300'],
                        'aggressive' => ['label' => 'Aggressive', 'desc' => 'Requires approval · 25 req/s, 0–100ms jitter, 300s timeout — INTERNAL USE ONLY', 'icon' => 'whatshot', 'color' => 'text-red-300'],
                    ];
                @endphp
                @foreach ($profiles as $name => $p)
                    <label class="relative">
                        <input type="radio" name="profile" value="{{ $name }}"
                               class="peer absolute opacity-0"
                               @checked(old('profile', 'balanced') === $name) required>
                        <div class="card !rounded-lg p-4 cursor-pointer hover:border-primary/40 peer-checked:border-primary peer-checked:bg-primary/10 transition-all h-full">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="material-symbols-rounded {{ $p['color'] }}">{{ $p['icon'] }}</span>
                                <span class="font-medium text-white">{{ $p['label'] }}</span>
                            </div>
                            <p class="text-xs text-gray-500">{{ $p['desc'] }}</p>
                        </div>
                    </label>
                @endforeach
            </div>
            @error('profile')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror

            {{-- Aggressive approval checkbox --}}
            <div id="aggressive-confirm" class="hidden">
                <label class="flex items-start gap-2 text-sm text-amber-200 bg-amber-500/10 border border-amber-500/30 rounded-lg p-3">
                    <input type="checkbox" name="aggressive_confirmed" value="1"
                           class="mt-1 rounded border-amber-500/40 bg-background text-amber-500 focus:ring-amber-500">
                    <span>I confirm I have written authorization for aggressive scanning on this target.</span>
                </label>
            </div>
        </div>

        {{-- Advanced config --}}
        <div class="card">
            <button type="button"
                    class="w-full flex items-center justify-between px-5 py-3 text-left"
                    data-collapse-toggle="advanced-config">
                <span class="font-medium text-white flex items-center gap-2">
                    <span class="material-symbols-rounded text-[18px]">tune</span> Advanced configuration
                </span>
                <span class="material-symbols-rounded text-gray-400 transition-transform" data-collapse-icon>chevron_right</span>
            </button>
            <div id="advanced-config" class="hidden px-5 pb-5 space-y-4 border-t border-white/5">
                <div>
                    <label for="custom_ports" class="label">Custom ports (comma-separated)</label>
                    <input id="custom_ports" type="text" name="config[custom_ports]" value="{{ old('config.custom_ports') }}"
                           class="input font-mono" placeholder="80,443,8080-8090">
                </div>
                <div>
                    <label for="excluded_paths" class="label">Excluded paths (one per line)</label>
                    <textarea id="excluded_paths" name="config[excluded_paths]" class="textarea font-mono text-xs" rows="3">{{ old('config.excluded_paths') }}</textarea>
                </div>
                <div>
                    <label for="custom_flags" class="label">Custom tool flags</label>
                    <input id="custom_flags" type="text" name="config[custom_flags]" value="{{ old('config.custom_flags') }}"
                           class="input font-mono" placeholder="--script=vuln --version-intensity=5">
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('scans.index') }}" class="btn-outline">Cancel</a>
            <button type="submit" class="btn-primary">
                <span class="material-symbols-rounded text-base">rocket_launch</span> Launch Scan
            </button>
        </div>
    </form>
</div>

@push('scripts')
<script>
    // Aggressive confirmation toggle
    const form = document.getElementById('scan-form');
    function syncAggressive() {
        const aggressive = document.querySelector('input[name="profile"]:checked')?.value === 'aggressive';
        document.getElementById('aggressive-confirm').classList.toggle('hidden', !aggressive);
    }
    document.querySelectorAll('input[name="profile"]').forEach((r) => r.addEventListener('change', syncAggressive));
    syncAggressive();

    form.addEventListener('submit', (e) => {
        if (document.querySelector('input[name="profile"]:checked')?.value === 'aggressive'
            && !document.querySelector('input[name="aggressive_confirmed"]')?.checked) {
            e.preventDefault();
            alert('Aggressive scans require written authorization. Please confirm the checkbox.');
        }
    });

    // Targets filtered by project
    const targetsByProject = @json($targetsByProject);
    const projectSelect = document.getElementById('project_id');
    const targetSelect = document.getElementById('target_id');
    const authNote = document.getElementById('target-auth-status');

    function renderTargets() {
        const pid = projectSelect.value;
        targetSelect.innerHTML = '<option value="">Choose a target…</option>';
        const list = targetsByProject[pid] || [];
        list.forEach((t) => {
            const opt = document.createElement('option');
            opt.value = t.id;
            opt.textContent = `${t.name} (${t.value})`;
            opt.dataset.authorized = t.authorized ? '1' : '0';
            targetSelect.appendChild(opt);
        });
        if (!list.length) {
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = 'No targets declared for this project';
            opt.disabled = true;
            targetSelect.appendChild(opt);
        }
        authNote.textContent = '';
    }
    projectSelect.addEventListener('change', renderTargets);
    targetSelect.addEventListener('change', () => {
        const opt = targetSelect.options[targetSelect.selectedIndex];
        const auth = opt?.dataset.authorized === '1';
        authNote.textContent = auth ? '✓ Target is authorized for scanning.' : '⚠ Target authorization pending — scan may be blocked.';
        authNote.className = auth ? 'text-xs text-emerald-400 mt-1' : 'text-xs text-amber-400 mt-1';
    });

    // If a project was preselected, load its targets
    if (projectSelect.value) renderTargets();
</script>
@endpush
@endsection
