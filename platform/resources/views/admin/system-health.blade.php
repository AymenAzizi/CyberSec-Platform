@extends('layouts.app')

@section('title', 'System Health')

@section('breadcrumb')
    <span class="text-gray-600">/</span>
    <span class="text-white">System Health</span>
@endsection

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="font-display text-2xl font-semibold text-white">System Health</h1>
            <p class="text-sm text-gray-400">Microservices, database, Redis and queue status.</p>
        </div>
        <button onclick="window.location.reload()" class="btn-outline text-xs"><span class="material-symbols-rounded text-[16px]">refresh</span> Recheck</button>
    </div>

    {{-- Microservices --}}
    <div>
        <h2 class="font-display text-lg text-white mb-3">Microservices</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            @forelse ($services as $service)
                <div class="card p-4">
                    <div class="flex items-start justify-between mb-2">
                        <div>
                            <div class="font-medium text-white">{{ $service['name'] }}</div>
                            <div class="text-xs text-gray-500 font-mono">{{ $service['url'] ?? '—' }}</div>
                        </div>
                        @if (($service['status'] ?? 'down') === 'up')
                            <span class="badge-success"><span class="material-symbols-rounded text-[12px]">check_circle</span> Up</span>
                        @else
                            <span class="badge-danger"><span class="material-symbols-rounded text-[12px]">error</span> Down</span>
                        @endif
                    </div>
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <div><span class="text-gray-500">Response:</span> <span class="text-white font-mono">{{ $service['response_ms'] ?? '—' }}ms</span></div>
                        <div><span class="text-gray-500">Last check:</span> <span class="text-white">{{ isset($service['last_check']) ? timeAgo($service['last_check']) : '—' }}</span></div>
                    </div>
                </div>
            @empty
                <div class="col-span-full text-center text-gray-500 py-6">No services registered.</div>
            @endforelse
        </div>
    </div>

    {{-- Database / Redis / Queue --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="card p-5">
            <h2 class="font-display text-lg text-white mb-3 flex items-center gap-2">
                <span class="material-symbols-rounded text-cyan-300">database</span> Database
            </h2>
            @if (!empty($dbStats))
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between"><span class="text-gray-500">Size</span><span class="text-white font-mono">{{ $dbStats['size'] ?? '—' }}</span></div>
                    <div class="flex justify-between"><span class="text-gray-500">Tables</span><span class="text-white">{{ $dbStats['tables'] ?? '—' }}</span></div>
                    <div class="border-t border-white/5 pt-2 mt-2 space-y-1">
                        @foreach (($dbStats['row_counts'] ?? []) as $table => $count)
                            <div class="flex justify-between text-xs">
                                <span class="text-gray-500 font-mono">{{ $table }}</span>
                                <span class="text-white">{{ number_format($count) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                <p class="text-sm text-gray-500">Database stats unavailable.</p>
            @endif
        </div>

        <div class="card p-5">
            <h2 class="font-display text-lg text-white mb-3 flex items-center gap-2">
                <span class="material-symbols-rounded text-red-300">bolt</span> Redis
            </h2>
            @if (!empty($redisStats))
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between"><span class="text-gray-500">Memory</span><span class="text-white font-mono">{{ $redisStats['memory'] ?? '—' }}</span></div>
                    <div class="flex justify-between"><span class="text-gray-500">Clients</span><span class="text-white">{{ $redisStats['clients'] ?? '—' }}</span></div>
                    <div class="flex justify-between"><span class="text-gray-500">Keyspace hits</span><span class="text-white">{{ $redisStats['keyspace_hits'] ?? '—' }}</span></div>
                    <div class="flex justify-between"><span class="text-gray-500">Hit ratio</span>
                        <span class="text-white">{{ isset($redisStats['hit_ratio']) ? number_format($redisStats['hit_ratio'] * 100, 1) . '%' : '—' }}</span>
                    </div>
                </div>
            @else
                <p class="text-sm text-gray-500">Redis stats unavailable.</p>
            @endif
        </div>

        <div class="card p-5">
            <h2 class="font-display text-lg text-white mb-3 flex items-center gap-2">
                <span class="material-symbols-rounded text-amber-300">queue</span> Queue
            </h2>
            @if (!empty($queueStats))
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between"><span class="text-gray-500">Pending jobs</span><span class="text-white">{{ $queueStats['pending'] ?? 0 }}</span></div>
                    <div class="flex justify-between"><span class="text-gray-500">Failed jobs</span>
                        <span class="{{ ($queueStats['failed'] ?? 0) > 0 ? 'text-danger' : 'text-white' }}">{{ $queueStats['failed'] ?? 0 }}</span>
                    </div>
                    @if (!empty($queueStats['recent_failed']))
                        <div class="border-t border-white/5 pt-2 mt-2 space-y-1 max-h-32 overflow-y-auto">
                            @foreach ($queueStats['recent_failed'] as $failed)
                                <div class="text-xs">
                                    <div class="text-gray-300 truncate">{{ $failed['job'] ?? '—' }}</div>
                                    <div class="text-gray-600 text-[10px]">{{ $failed['failed_at'] ?? '—' }}</div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @else
                <p class="text-sm text-gray-500">Queue stats unavailable.</p>
            @endif
        </div>
    </div>
</div>
@endsection
