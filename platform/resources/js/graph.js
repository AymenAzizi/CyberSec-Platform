// ============================================================
// Cytoscape.js knowledge-graph visualisation
// Loaded on demand by projects/graph.blade.php and scan show page.
// ============================================================
import cytoscape from 'cytoscape';

const TYPE_COLORS = {
    domain: '#06b6d4',        // cyan
    ip: '#10b981',            // green
    host: '#34d399',          // lighter green
    port: '#f59e0b',          // amber
    service: '#7c3aed',       // purple
    vulnerability: '#ef4444', // red
    impact: '#facc15',        // yellow
    default: '#94a3b8',
};

const TYPE_SHAPES = {
    domain: 'ellipse',
    ip: 'ellipse',
    host: 'rectangle',
    port: 'octagon',
    service: 'round-rectangle',
    vulnerability: 'diamond',
    impact: 'star',
    default: 'ellipse',
};

/**
 * Initialise a Cytoscape graph in the given container.
 *
 * @param {string|HTMLElement} container Selector or element
 * @param {{nodes:Array,edges:Array}} elements
 * @param {object} options
 */
window.initKnowledgeGraph = function (container, elements, options = {}) {
    if (!container) return null;
    const el = typeof container === 'string' ? document.querySelector(container) : container;
    if (!el) return null;

    const cy = cytoscape({
        container: el,
        elements: elements || [],
        style: [
            {
                selector: 'node',
                style: {
                    'background-color': (n) => TYPE_COLORS[n.data('type')] || TYPE_COLORS.default,
                    'shape': (n) => TYPE_SHAPES[n.data('type')] || TYPE_SHAPES.default,
                    'label': 'data(label)',
                    'color': '#e2e8f0',
                    'font-size': '10px',
                    'text-valign': 'bottom',
                    'text-halign': 'center',
                    'text-margin-y': 4,
                    'width': 28,
                    'height': 28,
                    'border-width': 1,
                    'border-color': '#1e293b',
                },
            },
            {
                selector: 'node[type="vulnerability"]',
                style: { 'width': 34, 'height': 34, 'border-width': 2, 'border-color': '#7f1d1d' },
            },
            {
                selector: 'node:selected',
                style: { 'border-width': 3, 'border-color': '#ffffff' },
            },
            {
                selector: 'edge',
                style: {
                    'width': 1.5,
                    'line-color': '#334155',
                    'target-arrow-color': '#334155',
                    'target-arrow-shape': 'triangle',
                    'curve-style': 'bezier',
                    'label': 'data(label)',
                    'color': '#64748b',
                    'font-size': '8px',
                    'text-background-color': '#0a0e1a',
                    'text-background-opacity': 0.7,
                    'text-background-padding': 2,
                },
            },
            {
                selector: '.impact-highlight',
                style: {
                    'background-color': '#facc15',
                    'line-color': '#facc15',
                    'target-arrow-color': '#facc15',
                    'border-color': '#facc15',
                },
            },
            {
                selector: '.impact-dim',
                style: { 'opacity': 0.2 },
            },
        ],
        layout: { name: options.layout || 'cose', padding: 24, animate: false },
        wheelSensitivity: 0.2,
    });

    // Click handler — surface asset details in the side panel
    cy.on('tap', 'node', (evt) => {
        const node = evt.target;
        const data = node.data();
        showAssetDetails(data);
        // Impact analysis — highlight reachable nodes from a vulnerability
        if (data.type === 'vulnerability') {
            runImpactAnalysis(cy, node);
        } else {
            cy.elements().removeClass('impact-highlight impact-dim');
        }
    });

    cy.on('tap', (evt) => {
        if (evt.target === cy) {
            hideAssetDetails();
            cy.elements().removeClass('impact-highlight impact-dim');
        }
    });

    return cy;
};

/**
 * Re-run a different layout.
 */
window.applyGraphLayout = function (cy, layout) {
    if (!cy) return;
    cy.layout({ name: layout, padding: 24, animate: false }).run();
};

/**
 * Filter the graph by asset type.
 */
window.filterGraphByType = function (cy, type) {
    if (!cy) return;
    cy.elements().style('display', 'element');
    if (type && type !== 'all') {
        cy.nodes().filter((n) => n.data('type') !== type).style('display', 'none');
        cy.edges().filter((e) => e.source().style('display') === 'none' || e.target().style('display') === 'none')
            .style('display', 'none');
    }
};

/**
 * Search nodes by label.
 */
window.searchGraph = function (cy, query) {
    if (!cy) return;
    const q = (query || '').trim().toLowerCase();
    cy.nodes().removeClass('impact-highlight');
    if (!q) return;
    cy.nodes().forEach((n) => {
        const label = (n.data('label') || '').toLowerCase();
        if (label.includes(q)) {
            n.addClass('impact-highlight');
        }
    });
};

// ============================================================
// Helpers — side panel
// ============================================================
function showAssetDetails(data) {
    const panel = document.getElementById('graph-asset-details');
    if (!panel) return;
    const props = data.properties || {};
    const propRows = Object.entries(props)
        .map(([k, v]) => `<div class="flex justify-between gap-3 text-xs py-1 border-b border-white/5">
            <span class="text-gray-500">${escapeHtml(k)}</span>
            <span class="text-gray-200 font-mono text-right break-all">${escapeHtml(formatValue(v))}</span>
        </div>`)
        .join('');

    panel.innerHTML = `
        <div class="flex items-start justify-between gap-2 mb-3">
            <div>
                <div class="text-xs uppercase tracking-wider text-gray-500">${escapeHtml(data.type || 'asset')}</div>
                <div class="font-display text-lg text-white break-words">${escapeHtml(data.label || '—')}</div>
            </div>
            <button type="button" data-action="close-details"
                class="text-gray-400 hover:text-white" aria-label="Close">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>
        ${data.value ? `<div class="text-xs text-gray-400 mb-2 break-all">${escapeHtml(data.value)}</div>` : ''}
        ${typeof data.risk_score === 'number' && data.risk_score > 0 ? `
            <div class="mb-3">
                <div class="flex items-center justify-between text-xs mb-1">
                    <span class="text-gray-500">Risk score</span>
                    <span class="text-white font-mono">${data.risk_score.toFixed(1)}/10</span>
                </div>
                <div class="h-1.5 rounded-full bg-white/5 overflow-hidden">
                    <div class="h-full bg-gradient-to-r from-secondary to-danger"
                         style="width: ${Math.min(100, (data.risk_score / 10) * 100).toFixed(1)}%"></div>
                </div>
            </div>` : ''}
        <div class="space-y-0.5">${propRows || '<div class="text-xs text-gray-500">No additional properties.</div>'}</div>
        ${data.type === 'vulnerability' ? `
            <div class="mt-4">
                <button type="button" data-action="impact-analysis"
                        class="btn-accent w-full text-xs">
                    <span class="material-symbols-rounded text-base">bolt</span>
                    Run Impact Analysis
                </button>
            </div>` : ''}
    `;
    panel.classList.remove('hidden');

    panel.querySelector('[data-action="close-details"]')?.addEventListener('click', hideAssetDetails);
    panel.querySelector('[data-action="impact-analysis"]')?.addEventListener('click', () => {
        const cy = window.__cyInstance;
        if (cy) {
            const node = cy.getElementById(data.id);
            runImpactAnalysis(cy, node);
        }
    });
}

function hideAssetDetails() {
    document.getElementById('graph-asset-details')?.classList.add('hidden');
}

/**
 * BFS blast-radius: highlight every node reachable from the seed node.
 */
function runImpactAnalysis(cy, seedNode) {
    if (!cy || !seedNode || seedNode.length === 0) return;
    cy.elements().removeClass('impact-highlight impact-dim');
    const reached = seedNode.closedNeighborhood();
    cy.elements().not(reached).addClass('impact-dim');
    reached.addClass('impact-highlight');
}

function escapeHtml(s) {
    return String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function formatValue(v) {
    if (v === null || v === undefined) return '—';
    if (typeof v === 'object') return JSON.stringify(v);
    return v;
}
