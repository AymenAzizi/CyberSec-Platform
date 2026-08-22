@props(['status' => 'pending'])

@php
    $statuses = [
        'pending'   => ['label' => 'Pending',   'class' => 'badge-neutral',  'icon' => 'schedule'],
        'queued'    => ['label' => 'Queued',    'class' => 'badge-cyan',     'icon' => 'hourglass_top'],
        'running'   => ['label' => 'Running',   'class' => 'badge-violet',   'icon' => 'play_circle'],
        'completed' => ['label' => 'Completed', 'class' => 'badge-success',  'icon' => 'check_circle'],
        'failed'    => ['label' => 'Failed',    'class' => 'badge-danger',   'icon' => 'error'],
        'cancelled' => ['label' => 'Cancelled', 'class' => 'badge-neutral',  'icon' => 'cancel'],
        'active'    => ['label' => 'Active',    'class' => 'badge-success',  'icon' => 'check_circle'],
        'paused'    => ['label' => 'Paused',    'class' => 'badge-medium',   'icon' => 'pause_circle'],
        'draft'     => ['label' => 'Draft',     'class' => 'badge-neutral',  'icon' => 'edit_note'],
        'archived'  => ['label' => 'Archived',  'class' => 'badge-neutral',  'icon' => 'inventory_2'],
        'approved'  => ['label' => 'Approved',  'class' => 'badge-success',  'icon' => 'verified'],
        'rejected'  => ['label' => 'Rejected',  'class' => 'badge-danger',   'icon' => 'block'],
        'expired'   => ['label' => 'Expired',   'class' => 'badge-medium',   'icon' => 'event_busy'],
    ];
    $st = $statuses[$status] ?? ['label' => ucfirst($status), 'class' => 'badge-neutral', 'icon' => 'circle'];
@endphp

<span class="{{ $st['class'] }}">
    <span class="material-symbols-rounded text-[12px]">{{ $st['icon'] }}</span>
    {{ $st['label'] }}
</span>
