@extends('layouts.app')

@section('title', 'OSINT · ' . $target->name)

@section('breadcrumb')
    <span class="text-gray-600">/</span>
    <a href="{{ route('osint.index') }}" class="hover:text-white">OSINT</a>
    <span class="text-gray-600">/</span>
    <span class="text-white">{{ $target->name }}</span>
@endsection

@section('content')
<div class="space-y-6">
    {{-- Header --}}
    <div class="card p-5">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h1 class="font-display text-2xl font-semibold text-white">{{ $target->name }}</h1>
                <div class="text-xs text-gray-500 mt-1 font-mono">{{ $target->domain_url ?: $target->ip_address }}</div>
                @if ($target->project)
                    <div class="text-xs text-gray-500 mt-1">
                        Project: <a href="{{ route('projects.show', $target->project) }}" class="text-cyan-400 hover:text-cyan-300">{{ $target->project->name }}</a>
                    </div>
                @endif
            </div>
            <form method="POST" action="{{ route('osint.run', $target) }}">
                @csrf
                <button type="submit" class="btn-primary">
                    <span class="material-symbols-rounded text-base">refresh</span> Refresh OSINT
                </button>
            </form>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="border-b border-white/5" data-tab-group="osint-tabs">
        <nav class="flex gap-1 overflow-x-auto tab-scroll">
            <button data-tab="whois"       class="nav-link !rounded-none border-b-2 border-transparent tab-active">WHOIS</button>
            <button data-tab="dns"         class="nav-link !rounded-none border-b-2 border-transparent">DNS</button>
            <button data-tab="ssl"         class="nav-link !rounded-none border-b-2 border-transparent">SSL</button>
            <button data-tab="subdomains"  class="nav-link !rounded-none border-b-2 border-transparent">Subdomains</button>
            <button data-tab="tech"        class="nav-link !rounded-none border-b-2 border-transparent">Tech Stack</button>
        </nav>
    </div>

    @php $data = $target->osint_data ?? []; @endphp

    {{-- WHOIS --}}
    <div data-tab-panel="whois" data-tab-group="osint-tabs">
        <div class="card p-5">
            @if (!empty($data['whois']))
                <dl class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                    @foreach (['registrar','creation_date','expiry_date','name_servers','registrant','status'] as $field)
                        @if (!empty($data['whois'][$field]))
                            <div>
                                <dt class="text-xs uppercase text-gray-500">{{ str_replace('_',' ', $field) }}</dt>
                                <dd class="text-white font-mono text-xs mt-0.5">{{ is_array($data['whois'][$field]) ? implode(', ', $data['whois'][$field]) : $data['whois'][$field] }}</dd>
                            </div>
                        @endif
                    @endforeach
                </dl>
            @else
                <p class="text-sm text-gray-500">No WHOIS data collected.</p>
            @endif
        </div>
    </div>

    {{-- DNS --}}
    <div data-tab-panel="dns" data-tab-group="osint-tabs" class="hidden">
        <div class="card p-5 space-y-4">
            @if (!empty($data['dns']))
                @foreach (['A','AAAA','MX','NS','TXT'] as $type)
                    @if (!empty($data['dns'][$type]))
                        <div>
                            <h3 class="text-xs uppercase tracking-wider text-gray-500 mb-2">{{ $type }} records</h3>
                            <div class="space-y-1">
                                @foreach ((array) $data['dns'][$type] as $rec)
                                    <div class="font-mono text-xs text-gray-300 bg-black/30 px-3 py-1.5 rounded border border-white/5">
                                        {{ is_array($rec) ? json_encode($rec) : $rec }}
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            @else
                <p class="text-sm text-gray-500">No DNS data collected.</p>
            @endif
        </div>
    </div>

    {{-- SSL --}}
    <div data-tab-panel="ssl" data-tab-group="osint-tabs" class="hidden">
        <div class="card p-5">
            @if (!empty($data['ssl']))
                <dl class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                    @foreach (['issuer','subject','valid_from','valid_to','serial','fingerprint_sha1','fingerprint_sha256','san'] as $field)
                        @if (!empty($data['ssl'][$field]))
                            <div>
                                <dt class="text-xs uppercase text-gray-500">{{ str_replace('_',' ', $field) }}</dt>
                                <dd class="text-white font-mono text-xs mt-0.5 break-all">{{ is_array($data['ssl'][$field]) ? implode(', ', $data['ssl'][$field]) : $data['ssl'][$field] }}</dd>
                            </div>
                        @endif
                    @endforeach
                </dl>
            @else
                <p class="text-sm text-gray-500">No SSL certificate data collected.</p>
            @endif
        </div>
    </div>

    {{-- Subdomains --}}
    <div data-tab-panel="subdomains" data-tab-group="osint-tabs" class="hidden">
        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Subdomain</th><th>Source</th><th>Discovered</th></tr></thead>
                    <tbody>
                        @forelse (($data['subdomains'] ?? []) as $sub)
                            <tr>
                                <td class="text-sm text-white font-mono">{{ is_array($sub) ? ($sub['name'] ?? '') : $sub }}</td>
                                <td class="text-xs">{{ is_array($sub) ? ($sub['source'] ?? '—') : 'crt.sh' }}</td>
                                <td class="text-xs">{{ is_array($sub) && !empty($sub['discovered_at']) ? formatDate($sub['discovered_at'], false) : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-gray-500 py-6">No subdomains discovered.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Tech Stack --}}
    <div data-tab-panel="tech" data-tab-group="osint-tabs" class="hidden">
        <div class="card p-5">
            @if (!empty($data['tech_stack']))
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach ($data['tech_stack'] as $tech)
                        <div class="card !rounded-lg p-3">
                            <div class="flex items-center justify-between">
                                <div class="text-sm text-white">{{ $tech['name'] ?? $tech }}</div>
                                @if (!empty($tech['version'])) <span class="badge-neutral text-[10px] font-mono">v{{ $tech['version'] }}</span> @endif
                            </div>
                            @if (!empty($tech['categories']))
                                <div class="flex flex-wrap gap-1 mt-2">
                                    @foreach ((array) $tech['categories'] as $cat)
                                        <span class="badge-cyan text-[10px]">{{ $cat }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-gray-500">No technology stack detected.</p>
            @endif
        </div>
    </div>
</div>
@endsection
