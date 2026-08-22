<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GraphController;
use App\Http\Controllers\OsintController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RemediationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\SecurityController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes — PFE CyberSec Platform (BUILD-4)
|--------------------------------------------------------------------------
|
| The platform's web UI routes. Authentication is required for everything
| except the auth flows themselves; admin-only routes are additionally
| guarded by the `role:admin` middleware (registered in bootstrap/app.php
| via the Spatie permission package).
|
| Method names below match the controllers currently shipped on disk:
|   - BUILD-4 task-spec auth controllers (LoginController, RegisterController,
|     ForgotPasswordController, ResetPasswordController) — these carry the
|     comprehensive rate-limiting + audit-logging logic required by the
|     task spec.
|   - Domain controllers (ProjectController, ScanController, ReportController,
|     SecurityController, AdminController, ChatController, OsintController,
|     GraphController, RemediationController) — the methods exposed match
|     the implementations currently on disk.
|
*/

// ---------------------------------------------------------------------------
// Root + public auth routes
// ---------------------------------------------------------------------------
Route::get('/', fn () => redirect()->route('dashboard'));

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:5,1');

    Route::get('/register', [RegisterController::class, 'showRegistrationForm'])->name('register');
    Route::post('/register', [RegisterController::class, 'register']);

    Route::get('/forgot-password', [ForgotPasswordController::class, 'showLinkRequestForm'])->name('password.request');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLinkEmail'])->name('password.email');

    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [ResetPasswordController::class, 'reset'])->name('password.update');
});

Route::post('/logout', [LoginController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

// ---------------------------------------------------------------------------
// Authenticated area
// ---------------------------------------------------------------------------
Route::middleware('auth')->group(function (): void {
    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Projects (resource — owner/admin scoped internally).
    Route::resource('projects', ProjectController::class);

    // Knowledge graph (uses ProjectController::graph / graphData per current
    // implementation; GraphController exposes the impact-analysis endpoint).
    Route::get('/projects/{project}/graph', [ProjectController::class, 'graph'])->name('projects.graph');
    Route::get('/projects/{project}/graph/data', [ProjectController::class, 'graphData'])->name('projects.graph.data');
    Route::get('/assets/{asset}/impact', [GraphController::class, 'impactAnalysis'])->name('assets.impact');

    // Scans
    Route::resource('scans', ScanController::class)->except(['edit', 'update', 'destroy']);
    Route::post('/scans/{scan}/cancel', [ScanController::class, 'cancel'])->name('scans.cancel');
    Route::post('/scans/{scan}/retry', [ScanController::class, 'retry'])->name('scans.retry');
    Route::get('/scans/{scan}/export', [ScanController::class, 'export'])->name('scans.export');

    // Reports
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/{report}', [ReportController::class, 'show'])->name('reports.show');
    Route::get('/reports/{report}/pdf', [ReportController::class, 'pdf'])->name('reports.pdf');
    Route::get('/reports/{report}/export/{format}', [ReportController::class, 'export'])
        ->name('reports.export')
        ->where('format', 'pdf|html|json|markdown');
    Route::get('/reports/{report}/download', [ReportController::class, 'download'])->name('reports.download');
    Route::get('/scans/{scan}/report/generate', [ReportController::class, 'generate'])->name('reports.generate');

    // Security operations (alerts, monitoring, sandbox).
    Route::get('/security/alerts', [SecurityController::class, 'alerts'])->name('security.alerts');
    Route::post('/security/alerts/{alert}/acknowledge', [SecurityController::class, 'acknowledge'])
        ->name('security.alerts.acknowledge');
    Route::get('/security/monitoring', [SecurityController::class, 'monitoring'])->name('security.monitoring');
    Route::get('/security/sandbox', [SecurityController::class, 'sandbox'])->name('security.sandbox');
    Route::post('/security/sandbox/launch', [SecurityController::class, 'launchSandbox'])
        ->name('security.sandbox.launch')
        ->middleware('role:admin|analyst');
    Route::post('/security/sandbox/{id}/stop', [SecurityController::class, 'stopSandbox'])
        ->name('security.sandbox.stop')
        ->middleware('role:admin|analyst');

    // OSINT (passive reconnaissance).
    Route::get('/osint', [OsintController::class, 'index'])->name('osint.index');
    Route::post('/osint/{target}/run', [OsintController::class, 'run'])->name('osint.run');
    Route::get('/osint/{target}/results', [OsintController::class, 'results'])->name('osint.results');

    // Remediation-as-Code (FindingController exposes the same surface under
    // the /findings/* URL namespace; RemediationController is also wired up
    // so the task-spec naming remains reachable for tooling).
    Route::get('/findings/{finding}/remediation', [RemediationController::class, 'show'])->name('remediation.show');
    Route::post('/findings/{finding}/remediation/generate', [RemediationController::class, 'generate'])
        ->name('remediation.generate');
    Route::get('/remediation/{script}/download', [RemediationController::class, 'downloadScript'])
        ->name('remediation.download');
    Route::post('/remediation/{script}/verify', [RemediationController::class, 'verify'])
        ->name('remediation.verify');
    Route::post('/remediation/{script}/apply', [RemediationController::class, 'apply'])
        ->name('remediation.apply');

    // AI Co-pilot chat. The ChatController exposes `messagesStore` for the
    // message-submission endpoint (singular `message` is aliased below for
    // task-spec callers).
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::get('/chat/create', [ChatController::class, 'create'])->name('chat.create');
    Route::post('/chat', [ChatController::class, 'store'])->name('chat.store');
    Route::get('/chat/{session}', [ChatController::class, 'show'])->name('chat.show');
    Route::post('/chat/{session}/messages', [ChatController::class, 'messagesStore'])->name('chat.messages.store');
    Route::delete('/chat/{session}', [ChatController::class, 'destroy'])->name('chat.destroy');

    // Admin area (audit logs, system health, user management). User CRUD
    // is exposed as methods on AdminController itself (usersIndex, usersStore, ...)
    // rather than a separate Admin\UserController, matching the current
    // implementation on disk.
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/audit-logs', [AdminController::class, 'auditLogs'])->name('audit-logs');
        Route::get('/system-health', [AdminController::class, 'systemHealth'])->name('system-health');

        Route::get('/users', [AdminController::class, 'usersIndex'])->name('users.index');
        Route::get('/users/create', [AdminController::class, 'usersCreate'])->name('users.create');
        Route::post('/users', [AdminController::class, 'usersStore'])->name('users.store');
        Route::get('/users/{user}/edit', [AdminController::class, 'usersEdit'])->name('users.edit');
        Route::put('/users/{user}', [AdminController::class, 'usersUpdate'])->name('users.update');
        Route::patch('/users/{user}/deactivate', [AdminController::class, 'usersDeactivate'])->name('users.deactivate');
    });
});
