@extends('layouts.app')

@section('title', $session->title ?: 'Chat')

@section('breadcrumb')
    <span class="text-gray-600">/</span>
    <a href="{{ route('chat.index') }}" class="hover:text-white">AI Chatbot</a>
    <span class="text-gray-600">/</span>
    <span class="text-white truncate max-w-xs">{{ $session->title ?: 'Untitled' }}</span>
@endsection

@section('content')
<div class="flex flex-col h-[calc(100vh-220px)] min-h-[480px]">
    {{-- Header --}}
    <div class="card p-4 mb-4 flex items-center justify-between">
        <div>
            <div class="font-display text-white">{{ $session->title ?: 'Untitled chat' }}</div>
            <div class="text-xs text-gray-500 mt-0.5 flex items-center gap-2">
                <span class="material-symbols-rounded text-[14px]">link</span>
                Context:
                @if ($session->project) <a href="{{ route('projects.show', $session->project) }}" class="text-cyan-400 hover:text-cyan-300">{{ $session->project->name }}</a> @else <span>Global</span> @endif
            </div>
        </div>
        <form method="POST" action="{{ route('chat.destroy', $session) }}" data-confirm="Delete this chat session?">
            @csrf @method('DELETE')
            <button type="submit" class="btn-ghost !p-2 text-red-300 hover:text-red-200" title="Delete chat">
                <span class="material-symbols-rounded">delete</span>
            </button>
        </form>
    </div>

    {{-- Messages --}}
    <div id="chat-messages" class="card flex-1 overflow-y-auto p-4 space-y-3 mb-4">
        @foreach ($session->messages as $message)
            <div class="flex {{ $message->role === 'user' ? 'justify-end' : 'justify-start' }}">
                <div class="max-w-[78%] {{ $message->role === 'user'
                    ? 'bg-primary text-white rounded-2xl rounded-br-sm px-4 py-2.5 text-sm'
                    : 'bg-white/5 border border-white/5 text-gray-200 rounded-2xl rounded-bl-sm px-4 py-2.5 text-sm' }}">
                    @if ($message->role === 'user')
                        {{ $message->content }}
                    @else
                        {!! Illuminate\Support\Str::markdown($message->content) !!}
                        @if (!empty($message->citations))
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @foreach ($message->citations as $c)
                                    <a href="{{ $c['url'] ?? route('remediation.show', $c['finding_id'] ?? 0) }}"
                                       class="badge-cyan text-[11px]">
                                        📖 {{ $c['title'] ?? 'Citation' }}
                                        @if (!empty($c['line'])) · L{{ $c['line'] }} @endif
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- Input --}}
    <form id="chat-form" method="POST" action="{{ route('chat.messages.store', $session) }}" class="card p-3 flex items-end gap-2">
        @csrf
        <textarea id="chat-input" name="content" rows="1"
                  class="input flex-1 resize-none max-h-32"
                  placeholder="Ask about your findings, assets, or remediation…"></textarea>
        <button type="submit" class="btn-primary !p-2.5" aria-label="Send">
            <span class="material-symbols-rounded">send</span>
        </button>
    </form>
</div>

@push('chat-scripts')
<script type="module">
    import '{{ Vite::asset('resources/js/chat.js') }}';
    window.addEventListener('DOMContentLoaded', () => {
        window.initChat({
            endpoint: '{{ route('chat.messages.store', $session) }}',
            container: '#chat-messages',
            form: '#chat-form',
            input: '#chat-input',
            sessionId: '{{ $session->id }}',
        });
        // Scroll to bottom on load
        const c = document.getElementById('chat-messages');
        if (c) c.scrollTop = c.scrollHeight;
    });
</script>
@endpush
@endsection
