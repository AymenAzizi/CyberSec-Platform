@props([
    'icon' => 'inbox',
    'title' => 'Nothing here yet',
    'message' => '',
    'actionLabel' => null,
    'actionHref' => null,
])

<div class="card p-10 text-center flex flex-col items-center gap-3">
    <div class="h-14 w-14 rounded-2xl bg-white/5 border border-white/10 flex items-center justify-center">
        <span class="material-symbols-rounded text-[28px] text-gray-400">{{ $icon }}</span>
    </div>
    <h3 class="font-display text-lg text-white">{{ $title }}</h3>
    @if ($message)
        <p class="text-sm text-gray-400 max-w-md">{{ $message }}</p>
    @endif
    @if ($actionLabel && $actionHref)
        <a href="{{ $actionHref }}" class="btn-primary mt-2">
            <span class="material-symbols-rounded text-base">add</span>
            {{ $actionLabel }}
        </a>
    @endif
</div>
