// tests/e2e/helpers/selectors.ts
// Centralised CSS selectors — keeps test specs resilient to UI tweaks.

export const SEL = {
  // Auth
  loginForm:          'form[action*="/login"]',
  registerForm:       'form[action*="/register"]',
  emailInput:         'input[type="email"]',
  passwordInput:      'input[type="password"]',
  submitButton:       'button[type="submit"]',
  csrfToken:          'meta[name="csrf-token"]',

  // Sidebar navigation
  sidebar:            'aside, nav[data-testid="sidebar"]',
  sidebarDashboard:  'a:has-text("Dashboard")',
  sidebarProjects:   'a:has-text("Projects")',
  sidebarScans:      'a:has-text("Scans")',
  sidebarReports:    'a:has-text("Reports")',
  sidebarAlerts:     'a:has-text("Alerts")',
  sidebarMonitoring: 'a:has-text("Monitoring")',
  sidebarSandbox:    'a:has-text("Sandbox")',
  sidebarGraph:      'a:has-text("Knowledge Graph")',
  sidebarOsint:      'a:has-text("OSINT")',
  sidebarChat:       'a:has-text("Chat")',
  sidebarUsers:      'a:has-text("Users")',
  sidebarAuditLogs:  'a:has-text("Audit Logs")',
  sidebarSystemHealth: 'a:has-text("System Health")',

  // Dashboard
  statsCard:         '[data-testid="stat-card"], .bg-\\[\\#131826\\]',
  recentScans:       'table tbody tr',
  recentAlerts:       '[data-testid="recent-alerts"] li, .alert-item',

  // Projects
  projectCard:       '[data-testid="project-card"], .project-card',
  newProjectBtn:     'a:has-text("New Project")',
  projectNameInput:  'input[name="name"]',
  projectClientInput: 'input[name="client"]',

  // Scans
  newScanBtn:        'a:has-text("New Scan")',
  scanTypeSelect:    'select[name="scan_type"], [data-testid="scan-type"]',
  scanProfileSelect: 'select[name="profile"], [data-testid="profile"]',
  targetInput:       'input[name="target"]',
  scanRow:           'table tbody tr',

  // Reports
  generateReportBtn: 'a:has-text("Generate Report"), button:has-text("Generate")',
  reportRow:         'table tbody tr',

  // Alerts
  acknowledgeBtn:    'button:has-text("Acknowledge")',
  alertRow:          'table tbody tr, [data-testid="alert"]',

  // Sandbox
  launchSandboxBtn:  'button:has-text("Launch")',
  stopSandboxBtn:    'button:has-text("Stop")',
  sandboxContainer:  '[data-testid="sandbox-container"]',

  // Knowledge graph
  cytoscapeCanvas:   'canvas[data-id="cy"], canvas',

  // Chat
  chatInput:         'textarea[name="message"], input[name="message"]',
  chatSendBtn:       'button[type="submit"]',
  chatMessage:       '.message, [data-testid="chat-message"]',

  // Admin
  userRow:           'table tbody tr',
  auditLogRow:       'table tbody tr',
  healthService:     '[data-testid="health-service"]',

  // Common
  flashSuccess:      '.bg-green-500, [data-flash="success"]',
  flashError:        '.bg-red-500, [data-flash="error"]',
  pagination:        '.pagination, [role="navigation"]',
} as const;
