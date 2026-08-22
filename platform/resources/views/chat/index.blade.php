@extends('layouts.app')

@section('title', 'AI Chatbot')

@section('breadcrumb')
    <span class="text-gray-600">/</span>
    <span class="text-white">AI Chatbot</span>
@endsection

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h1 class="font-display text-2xl font-semibold text-white">AI Chatbot</h1>
            <p class="text-sm text-gray-400">Security co-pilot — ask about findings, assets and remediation.</p>
        </div>
        <a href="{{ route('chat.create') }}" class="btn-primary">
            <span class="material-symbols-rounded text-base">add</span> New Chat
        </a>
    </div>

    @if ($sessions->isEmpty())
        <x-empty-state icon="smart_toy" title="No chat sessions yet"
            message="Start a new chat to ask the AI assistant about your security data."
            action-label="New Chat" action-href="{{ route('chat.create') }}" />
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach ($sessions as $session)
                <a href="{{ route('chat.show', $session) }}" class="card-hover p-5 block">
                    <div class="flex items-start justify-between gap-2 mb-2">
                        <h3 class="font-display text-white">{{ $session->title ?: 'Untitled chat' }}</h3>
                        <span class="material-symbols-rounded text-gray-500">smart_toy</span>
                    </div>
                    <p class="text-sm text-gray-400 line-clamp-2 mb-3">
                        {{ $session->messages->last()?->content ?? 'No messages yet.' }}
                    </p>
                    <div class="flex items-center justify-between text-xs text-gray-500">
                        <span>{{ $session->messages->count() }} message{{ $session->messages->count() === 1 ? '' : 's' }}</span>
                        <span>{{ $session->updated_at?->diffForHumans() }}</span>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
@endsection
