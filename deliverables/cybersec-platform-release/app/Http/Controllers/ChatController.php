<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        $sessions = ChatSession::query()
            ->where('user_id', $request->user()->id)
            ->with(['messages' => fn ($q) => $q->latest()->limit(1), 'project'])
            ->latest()
            ->paginate(15);

        return view('chat.index', compact('sessions'));
    }

    public function create(Request $request)
    {
        $session = ChatSession::create([
            'user_id'    => $request->user()->id,
            'title'      => 'New chat · '.now()->format('M d, H:i'),
        ]);

        return redirect()->route('chat.show', $session);
    }

    public function store(Request $request)
    {
        // Floating chatbot — store a session on the fly.
        $session = ChatSession::create([
            'user_id' => $request->user()->id,
            'title'   => 'Quick chat · '.now()->format('M d, H:i'),
        ]);

        return $this->sendMessage($request, $session);
    }

    public function show(ChatSession $session)
    {
        $this->authorizeSession($session);
        $session->load(['messages', 'project']);

        return view('chat.show', compact('session'));
    }

    public function destroy(ChatSession $session)
    {
        $this->authorizeSession($session);
        $session->delete();

        return redirect()->route('chat.index')->with('success', 'Chat deleted.');
    }

    public function messagesStore(Request $request, ChatSession $session)
    {
        $this->authorizeSession($session);
        return $this->sendMessage($request, $session);
    }

    private function sendMessage(Request $request, ChatSession $session)
    {
        $validated = $request->validate([
            'content'   => ['required', 'string', 'max:5000'],
            'floating'  => ['nullable', 'boolean'],
        ]);

        $userMsg = ChatMessage::create([
            'chat_session_id' => $session->id,
            'role'            => ChatMessage::ROLE_USER,
            'content'         => $validated['content'],
        ]);

        $reply = $this->askAssistant($session, $validated['content']);

        $assistantMsg = ChatMessage::create([
            'chat_session_id' => $session->id,
            'role'            => ChatMessage::ROLE_ASSISTANT,
            'content'         => $reply['content'] ?? 'I could not reach the AI service.',
            'citations'       => $reply['citations'] ?? null,
        ]);

        if ($session->title === null || str_starts_with($session->title, 'New chat')) {
            $session->update(['title' => Str::limit($validated['content'], 60)]);
        }

        if ($request->boolean('floating')) {
            return response()->json([
                'reply'     => $assistantMsg->content,
                'citations' => $assistantMsg->citations,
                'session_id' => $session->id,
            ]);
        }

        return back();
    }

    private function askAssistant(ChatSession $session, string $content): array
    {
        // Try the external AI gateway microservice first (Docker deployment).
        try {
            $gateway = config('services.api_gateway.url', 'http://api-gateway:8080');
            $res = Http::timeout(60)->post($gateway.'/api/ai/chat', [
                'session_id' => $session->id,
                'project_id' => $session->project_id,
                'message'    => $content,
                'history'    => $session->messages()->latest()->limit(10)->get(['role', 'content'])->toArray(),
            ]);

            if ($res->ok()) {
                $data = $res->json();
                return [
                    'content'   => $data['reply'] ?? $data['content'] ?? $data['message'] ?? '',
                    'citations' => $data['citations'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // Fallback: call z-ai CLI directly (works in sandbox without microservices).
        try {
            $reply = $this->askZAiCli($session, $content);
            if ($reply !== null) {
                return [
                    'content'   => $reply,
                    'citations' => null,
                ];
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return [
            'content' => "I couldn't reach the AI service right now. Please try again in a moment.\n\nYour message was logged as **#{$session->id}**.",
            'citations' => null,
        ];
    }

    /**
     * Direct call to the z-ai CLI (GLM-4-Plus) when the Python AI microservice
     * is unreachable. Builds a cybersecurity-aware system prompt, attaches the
     * last 6 turns of conversation as context, and parses the JSON reply.
     */
    private function askZAiCli(ChatSession $session, string $userMessage): ?string
    {
        $history = $session->messages()
            ->where('id', '!=', optional($session->messages()->latest()->first())->id)
            ->latest()->limit(6)->get(['role', 'content']);

        $systemPrompt = "You are the CyberSec Platform assistant, an expert in offensive and defensive cybersecurity. "
            ."You help analysts interpret scan findings (nmap, nuclei, gobuster, OSINT), explain CVEs and CVSS scores, "
            ."suggest remediation steps, and reason about attack-surface blast radius. "
            ."Be concise, technical, and actionable. Use Markdown for code/config snippets. "
            ."If asked about a specific CVE or port, give the canonical explanation.";

        // Build conversation context for the CLI (single-shot prompt).
        $context = "Conversation history:\n";
        foreach ($history->reverse() as $msg) {
            $role = $msg->role === 'user' ? 'User' : 'Assistant';
            $context .= "{$role}: {$msg->content}\n";
        }
        $context .= "\nUser: {$userMessage}\n\nAssistant:";

        $tempFile = tempnam(sys_get_temp_dir(), 'zai_');
        $exitCode = 0;
        $output = [];

        // Locate the z-ai CLI binary dynamically (don't hardcode /usr/local/bin).
        $zaiBin = trim((string) shell_exec('command -v z-ai 2>/dev/null')) ?: 'z-ai';
        if (!file_exists($zaiBin) && file_exists('/usr/local/bin/z-ai')) {
            $zaiBin = '/usr/local/bin/z-ai';
        }

        // z-ai CLI expects space-separated args: --prompt "value" --system "value"
        // Write context+system to temp files to avoid shell-escaping issues with newlines/quotes.
        $promptFile = tempnam(sys_get_temp_dir(), 'zaip_');
        $systemFile = tempnam(sys_get_temp_dir(), 'zais_');
        file_put_contents($promptFile, $context);
        file_put_contents($systemFile, $systemPrompt);

        $cmd = sprintf(
            '%s chat --prompt %s --system %s 2>&1',
            escapeshellarg($zaiBin),
            escapeshellarg(file_get_contents($promptFile)),
            escapeshellarg(file_get_contents($systemFile))
        );
        exec($cmd, $output, $exitCode);

        @unlink($promptFile);
        @unlink($systemFile);

        if ($exitCode !== 0) {
            @unlink($tempFile);
            return null;
        }

        $raw = implode("\n", $output);

        // The CLI prints status lines then a JSON object. Extract the JSON.
        $jsonStart = strpos($raw, '{');
        if ($jsonStart === false) {
            @unlink($tempFile);
            return $raw ?: null;
        }

        $json = substr($raw, $jsonStart);
        $data = json_decode($json, true);
        @unlink($tempFile);

        return $data['choices'][0]['message']['content'] ?? null;
    }

    private function authorizeSession(ChatSession $session): void
    {
        abort_unless($session->user_id === auth()->id(), 403);
    }
}
