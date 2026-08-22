@props(['profile' => 'balanced'])

@php
    $profiles = [
        'silent'     => ['label' => 'Silent',     'class' => 'badge-cyan',    'icon' => 'visibility_off'],
        'balanced'   => ['label' => 'Balanced',   'class' => 'badge-violet',  'icon' => 'balance'],
        'aggressive' => ['label' => 'Aggressive', 'class' => 'badge-danger',  'icon' => 'whatshot'],
    ];
    $p = $profiles[$profile] ?? ['label' => ucfirst($profile), 'class' => 'badge-neutral', 'icon' => 'tune'];
@endphp

<span class="{{ $p['class'] }}">
    <span class="material-symbols-rounded text-[12px]">{{ $p['icon'] }}</span>
    {{ $p['label'] }}
</span>
