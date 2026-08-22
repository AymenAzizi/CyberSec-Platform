import axios from 'axios';
import './bootstrap';

// Expose libs globally for inline Blade handlers
import * as echarts from 'echarts';
window.echarts = echarts;

// Register a dark cybersec ECharts theme so charts match the UI palette.
const cybersecTheme = {
    backgroundColor: 'transparent',
    textStyle: { fontFamily: 'Inter, sans-serif', color: '#cbd5e1' },
    title: { textStyle: { color: '#e2e8f0' }, subtextStyle: { color: '#94a3b8' } },
    legend: { textStyle: { color: '#94a3b8' } },
    tooltip: {
        backgroundColor: '#131826',
        borderColor: '#2a3142',
        textStyle: { color: '#e2e8f0' },
    },
    categoryAxis: {
        axisLine: { lineStyle: { color: '#2a3142' } },
        axisLabel: { color: '#94a3b8' },
        splitLine: { lineStyle: { color: '#1e2433' } },
    },
    valueAxis: {
        axisLine: { lineStyle: { color: '#2a3142' } },
        axisLabel: { color: '#94a3b8' },
        splitLine: { lineStyle: { color: '#1e2433' } },
    },
    color: ['#7c3aed', '#06b6d4', '#f59e0b', '#10b981', '#ef4444', '#f97316', '#6b7280'],
};
echarts.registerTheme('cybersec', cybersecTheme);
window.echartsTheme = 'cybersec';

// ============================================================
// Axios defaults — CSRF + base URL
// ============================================================
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
if (csrfToken) {
    axios.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken;
    window.axios = axios;
}
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// ============================================================
// Global helper functions (exposed on window)
// ============================================================

/**
 * Format a number of seconds into a compact human duration.
 */
window.formatDuration = function (seconds) {
    if (seconds === null || seconds === undefined) return '—';
    seconds = Number(seconds);
    if (Number.isNaN(seconds)) return '—';
    if (seconds < 1) return '<1s';
    if (seconds < 60) return `${Math.round(seconds)}s`;
    const m = Math.floor(seconds / 60);
    const s = Math.round(seconds % 60);
    if (seconds < 3600) return s ? `${m}m ${s}s` : `${m}m`;
    const h = Math.floor(m / 60);
    const mm = m % 60;
    return mm ? `${h}h ${mm}m` : `${h}h`;
};

/**
 * Format a byte count into a human-readable string.
 */
window.formatBytes = function (bytes, decimals = 1) {
    if (bytes === null || bytes === undefined) return '—';
    bytes = Number(bytes);
    if (!Number.isFinite(bytes) || bytes <= 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.min(Math.floor(Math.log(bytes) / Math.log(k)), sizes.length - 1);
    return `${parseFloat((bytes / Math.pow(k, i)).toFixed(decimals))} ${sizes[i]}`;
};

/**
 * Format an ISO date string for display.
 */
window.formatDate = function (iso, withTime = true) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';
    const date = d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: '2-digit' });
    if (!withTime) return date;
    const time = d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
    return `${date} · ${time}`;
};

/**
 * Relative "time ago" formatter.
 */
window.timeAgo = function (iso) {
    if (!iso) return '—';
    const then = new Date(iso).getTime();
    if (Number.isNaN(then)) return '—';
    const diff = Math.max(0, Date.now() - then);
    const sec = Math.floor(diff / 1000);
    if (sec < 60) return `${sec}s ago`;
    const min = Math.floor(sec / 60);
    if (min < 60) return `${min}m ago`;
    const hr = Math.floor(min / 60);
    if (hr < 24) return `${hr}h ago`;
    const day = Math.floor(hr / 24);
    if (day < 30) return `${day}d ago`;
    return window.formatDate(iso, false);
};

/**
 * Copy text to the clipboard and return a Promise<bool>.
 */
window.copyToClipboard = async function (text) {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
        } else {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
        }
        return true;
    } catch (err) {
        console.error('copyToClipboard failed', err);
        return false;
    }
};

// ============================================================
// Flash message auto-dismiss
// ============================================================
window.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-flash]').forEach((el) => {
        const delay = Number(el.dataset.flashDelay || 5000);
        setTimeout(() => {
            el.style.transition = 'opacity .3s, transform .3s';
            el.style.opacity = '0';
            el.style.transform = 'translateY(-8px)';
            setTimeout(() => el.remove(), 350);
        }, delay);
    });
});

// ============================================================
// Sidebar toggle (mobile)
// ============================================================
window.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    const toggle = document.getElementById('sidebar-toggle');
    const close = document.getElementById('sidebar-close');

    const openSidebar = () => {
        sidebar?.classList.remove('-translate-x-full');
        overlay?.classList.remove('hidden');
    };
    const closeSidebar = () => {
        sidebar?.classList.add('-translate-x-full');
        overlay?.classList.add('hidden');
    };

    toggle?.addEventListener('click', openSidebar);
    close?.addEventListener('click', closeSidebar);
    overlay?.addEventListener('click', closeSidebar);
});

// ============================================================
// Theme toggle (dark default, switch to light)
// ============================================================
window.addEventListener('DOMContentLoaded', () => {
    const stored = localStorage.getItem('theme');
    if (stored === 'light') document.documentElement.classList.add('light');

    const toggle = document.getElementById('theme-toggle');
    toggle?.addEventListener('click', () => {
        const isLight = document.documentElement.classList.toggle('light');
        localStorage.setItem('theme', isLight ? 'light' : 'dark');
        // Notify ECharts instances so they can re-render if needed
        window.dispatchEvent(new CustomEvent('theme-changed', { detail: { light: isLight } }));
    });
});

// ============================================================
// Working search filter — filters visible rows/cards by data-search text
// ============================================================
window.addEventListener('DOMContentLoaded', () => {
    const search = document.getElementById('global-search');
    if (!search) return;
    search.addEventListener('input', (e) => {
        const q = e.target.value.trim().toLowerCase();
        // 1) Filter rows in any table that has [data-searchable]
        document.querySelectorAll('[data-searchable] tbody tr').forEach((row) => {
            const text = (row.textContent || '').toLowerCase();
            row.style.display = !q || text.includes(q) ? '' : 'none';
        });
        // 2) Filter cards/items that carry [data-search-item]
        document.querySelectorAll('[data-search-item]').forEach((card) => {
            const text = (card.dataset.searchItem || card.textContent || '').toLowerCase();
            card.style.display = !q || text.includes(q) ? '' : 'none';
        });
    });
});

// ============================================================
// Generic confirm-on-submit for forms with [data-confirm]
// ============================================================
window.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (e) => {
            const msg = form.dataset.confirm || 'Are you sure?';
            if (!window.confirm(msg)) {
                e.preventDefault();
            }
        });
    });

    // Inline confirm buttons (anchor / button with data-confirm)
    document.querySelectorAll('[data-confirm]:not(form)').forEach((el) => {
        if (el.tagName === 'BUTTON' || el.tagName === 'A') {
            const handler = (e) => {
                const msg = el.dataset.confirm || 'Are you sure?';
                if (!window.confirm(msg)) e.preventDefault();
            };
            el.addEventListener('click', handler);
        }
    });
});

// ============================================================
// Tabs — generic [data-tab] / [data-tab-panel]
// ============================================================
window.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-tab-group]').forEach((group) => {
        const tabs = group.querySelectorAll('[data-tab]');
        const panels = document.querySelectorAll(
            `[data-tab-panel][data-tab-group="${group.dataset.tabGroup}"]`,
        );
        tabs.forEach((tab) => {
            tab.addEventListener('click', (e) => {
                e.preventDefault();
                const target = tab.dataset.tab;
                tabs.forEach((t) => t.classList.remove('tab-active', 'bg-primary/15', 'text-white'));
                tab.classList.add('tab-active', 'bg-primary/15', 'text-white');
                panels.forEach((p) => {
                    p.classList.toggle('hidden', p.dataset.tabPanel !== target);
                });
                // Persist active tab in the URL hash
                if (window.location.hash !== `#${target}`) {
                    history.replaceState(null, '', `#${target}`);
                }
            });
        });
        // Open tab from URL hash on load
        const initial = window.location.hash.replace('#', '');
        if (initial) {
            const trigger = group.querySelector(`[data-tab="${initial}"]`);
            trigger?.click();
        }
    });
});

// ============================================================
// Collapsible sections
// ============================================================
window.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-collapse-toggle]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const target = document.getElementById(btn.dataset.collapseToggle);
            if (!target) return;
            const isHidden = target.classList.toggle('hidden');
            btn.setAttribute('aria-expanded', String(!isHidden));
            const icon = btn.querySelector('[data-collapse-icon]');
            if (icon) icon.style.transform = isHidden ? '' : 'rotate(90deg)';
        });
    });
});

// ============================================================
// Copy buttons with [data-copy-target]
// ============================================================
window.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-copy-target]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const target = document.getElementById(btn.dataset.copyTarget);
            if (!target) return;
            const ok = await window.copyToClipboard(target.textContent || target.value || '');
            const original = btn.innerHTML;
            btn.innerHTML = '<span class="material-symbols-rounded text-base">check</span> Copied';
            setTimeout(() => { btn.innerHTML = original; }, 1500);
        });
    });
});

// ============================================================
// Notifications dropdown toggle
// ============================================================
window.addEventListener('DOMContentLoaded', () => {
    const bell = document.getElementById('notifications-bell');
    const dropdown = document.getElementById('notifications-dropdown');
    if (!bell || !dropdown) return;
    bell.addEventListener('click', (e) => {
        e.stopPropagation();
        dropdown.classList.toggle('hidden');
    });
    document.addEventListener('click', (e) => {
        if (!dropdown.contains(e.target) && !bell.contains(e.target)) {
            dropdown.classList.add('hidden');
        }
    });
});

// ============================================================
// Floating chatbot widget toggle
// ============================================================
window.addEventListener('DOMContentLoaded', () => {
    const btn = document.getElementById('chatbot-fab');
    const panel = document.getElementById('chatbot-panel');
    const close = document.getElementById('chatbot-close');
    if (!btn || !panel) return;
    btn.addEventListener('click', () => panel.classList.toggle('hidden'));
    close?.addEventListener('click', () => panel.classList.add('hidden'));
});
