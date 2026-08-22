@props([
    'icon' => 'grid_view',
    'label' => '',
    'value' => 0,
    'hint' => null,
    'color' => 'violet',
    'pulse' => false,
    'trend' => null,
    'href' => null,
])

@php
    $colorMap = [
        'violet' => 'from-violet-500/20 to-violet-500/0 text-violet-300',
        'cyan'   => 'from-cyan-500/20 to-cyan-500/0 text-cyan-300',
        'amber'  => 'from-amber-500/20 to-amber-500/0 text-amber-300',
        'emerald'=> 'from-emerald-500/20 to-emerald-500/0 text-emerald-300',
        'red'    => 'from-red-500/20 to-red-500/0 text-red-300',
        'orange' => 'from-orange-500/20 to-orange-500/0 text-orange-300',
    ];
    $colorClass = $colorMap[$color] ?? $colorMap['violet'];

    $shouldPulse = $pulse && (is_numeric($value) ? ((float) $value > 0) : true);

    $tag = $href ? 'a' : 'div';
    $tagAttrs = $href ? ['href' => $href] : [];
@endphp

<{{ $tag }} {{ collect($tagAttrs)->map(fn($v,$k) => $k.'="'.$v.'"')->implode(' ') }}
   class="card-hover p-4 block relative overflow-hidden group">
    <div class="absolute inset-0 bg-gradient-to-br {{ $colorClass }} opacity-60 pointer-events-none"></div>
    <div class="relative">
        <div class="flex items-start justify-between gap-2 mb-3">
            <div class="h-9 w-9 rounded-lg bg-white/5 border border-white/10 flex items-center justify-center">
                <span class="material-symbols-rounded text-[20px]">{{ $icon }}</span>
            </div>
            @if ($shouldPulse)
                <span class="pulse-dot bg-emerald-400" title="active"></span>
            @endif
        </div>
        <div class="text-2xl font-display font-semibold text-white tabular-nums">{{ $value }}</div>
        <div class="text-xs text-gray-400 mt-0.5">{{ $label }}</div>
        @if ($hint)
            <div class="text-[11px] text-gray-500 mt-1.5">{{ $hint }}</div>
        @endif
        @if ($trend !== null)
            <div class="text-[11px] mt-2 {{ $trend >= 0 ? 'text-emerald-400' : 'text-red-400' }}">
                <span class="material-symbols-rounded text-[12px] align-middle">
                    {{ $trend >= 0 ? 'trending_up' : 'trending_down' }}
                </span>
                {{ abs($trend) }}% vs last week
            </div>
        @endif
    </div>
</{{ $tag }}>
