@props(['severity' => 'info', 'size' => 'sm'])

@php
    $severities = [
        'critical' => ['label' => 'Critical', 'class' => 'badge-critical', 'icon' => 'priority_high'],
        'high'     => ['label' => 'High',     'class' => 'badge-high',     'icon' => 'arrow_upward'],
        'medium'   => ['label' => 'Medium',   'class' => 'badge-medium',   'icon' => 'remove'],
        'low'      => ['label' => 'Low',      'class' => 'badge-low',      'icon' => 'arrow_downward'],
        'info'     => ['label' => 'Info',     'class' => 'badge-info',     'icon' => 'info'],
    ];
    $sev = $severities[$severity] ?? $severities['info'];
    $sizeClass = $size === 'xs' ? 'text-[10px] px-1.5 py-0.5' : 'text-xs';
@endphp

<span class="{{ $sev['class'] }} {{ $sizeClass }}">
    <span class="material-symbols-rounded text-[12px]">{{ $sev['icon'] }}</span>
    {{ $sev['label'] }}
</span>
