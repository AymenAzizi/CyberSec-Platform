// ============================================================
// Chat widget — message send/receive, markdown rendering,
// auto-scroll, typing indicator.
// ============================================================
import { marked } from 'marked';

marked.setOptions({ breaks: true, gfm: true });

function escapeHtml(s) {
    return String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function renderMarkdown(text) {
    if (!text) return '';
    try {
        return marked.parse(text);
    } catch (e) {
        return `<p>${escapeHtml(text)}</p>`;
    }
}

/**
 * Initialise a chat panel.
 *
 * @param {object} cfg
 * @param {string} cfg.endpoint   POST URL for sending messages
 * @param {string} cfg.container  Selector for the messages container
 * @param {string} cfg.form       Selector for the input form
 * @param {string} cfg.input      Selector for the textarea
 * @param {string} cfg.sessionId  Current chat session id (optional)
 */
window.initChat = function (cfg) {
    const container = document.querySelector(cfg.container);
    const form = document.querySelector(cfg.form);
    const input = document.querySelector(cfg.input);
    if (!container || !form || !input) return;

    // Auto-scroll to bottom on initial load.
    scrollToBottom();

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const content = input.value.trim();
        if (!content) return;

        const formData = new FormData(form);
        formData.set('content', content);

        // Render the user message immediately.
        appendMessage('user', content);
        input.value = '';
        input.style.height = 'auto';
        const typingEl = appendTypingIndicator();

        try {
            const res = await fetch(cfg.endpoint, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Accept': 'application/json',
                },
                body: formData,
            });

            typingEl.remove();

            if (!res.ok) {
                const txt = await res.text();
                appendMessage('assistant', `**Request failed (${res.status}).**\n\n${escapeHtml(txt)}`);
                return;
            }

            const ct = res.headers.get('content-type') || '';
            if (ct.includes('application/json')) {
                const json = await res.json();
                const reply = json.reply || json.content || json.message || '(no response)';
                if (json.session_id) {
                    cfg.endpoint = `/chat/${json.session_id}/messages`;
                }
                appendMessage('assistant', reply, json.citations);
            } else {
                const text = await res.text();
                appendMessage('assistant', text);
            }
        } catch (err) {
            typingEl.remove();
            appendMessage('assistant', `**Network error.** ${escapeHtml(err.message || '')}`);
        }
    });

    // Auto-grow the textarea
    input.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = `${Math.min(180, input.scrollHeight)}px`;
    });

    // Enter to submit, Shift+Enter for newline
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            form.requestSubmit();
        }
    });

    function appendMessage(role, content, citations) {
        const wrap = document.createElement('div');
        const isUser = role === 'user';
        wrap.className = isUser
            ? 'flex justify-end'
            : 'flex justify-start';

        const bubble = document.createElement('div');
        bubble.className = isUser
            ? 'max-w-[78%] bg-primary text-white rounded-2xl rounded-br-sm px-4 py-2.5 text-sm'
            : 'max-w-[78%] bg-white/5 border border-white/5 text-gray-200 rounded-2xl rounded-bl-sm px-4 py-2.5 text-sm';

        bubble.innerHTML = isUser ? escapeHtml(content).replace(/\n/g, '<br>') : renderMarkdown(content);

        if (citations && citations.length) {
            const cits = document.createElement('div');
            cits.className = 'mt-2 flex flex-wrap gap-1.5';
            citations.forEach((c, idx) => {
                const chip = document.createElement('a');
                chip.href = c.url || `#finding-${c.finding_id || idx}`;
                chip.className = 'badge-cyan text-[11px]';
                chip.textContent = `📖 ${c.title || `Citation ${idx + 1}`}`;
                if (c.line) chip.textContent += ` · L${c.line}`;
                cits.appendChild(chip);
            });
            bubble.appendChild(cits);
        }

        wrap.appendChild(bubble);
        container.appendChild(wrap);
        scrollToBottom();
        return wrap;
    }

    function appendTypingIndicator() {
        const wrap = document.createElement('div');
        wrap.className = 'flex justify-start';
        wrap.innerHTML = `
            <div class="bg-white/5 border border-white/5 rounded-2xl rounded-bl-sm px-4 py-3 flex items-center gap-1.5">
                <span class="h-2 w-2 rounded-full bg-cyan-400 animate-bounce" style="animation-delay: 0ms"></span>
                <span class="h-2 w-2 rounded-full bg-cyan-400 animate-bounce" style="animation-delay: 120ms"></span>
                <span class="h-2 w-2 rounded-full bg-cyan-400 animate-bounce" style="animation-delay: 240ms"></span>
            </div>`;
        container.appendChild(wrap);
        scrollToBottom();
        return wrap;
    }

    function scrollToBottom() {
        requestAnimationFrame(() => {
            container.scrollTop = container.scrollHeight;
        });
    }
};

// ============================================================
// Floating chatbot widget (sidebar mini chat)
// ============================================================
window.addEventListener('DOMContentLoaded', () => {
    const fab = document.getElementById('chatbot-fab');
    const panel = document.getElementById('chatbot-panel');
    if (!fab || !panel) return;

    const sendEndpoint = panel.dataset.endpoint || '/chat';
    window.initChat({
        endpoint: sendEndpoint,
        container: '#chatbot-messages',
        form: '#chatbot-form',
        input: '#chatbot-input',
    });
});
