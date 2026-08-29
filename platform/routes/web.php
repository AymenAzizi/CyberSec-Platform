<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FindingController;
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
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:120,1');

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
    // Dashboard (all authenticated users, scoped internally)
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Projects:
    // - Index & Show are accessible to all authenticated users (scoped by controller).
    // - Create, Store, Edit, Update, Destroy are restricted to Admin & Analyst.
    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');

    Route::middleware('role:admin|analyst')->group(function (): void {
        Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
        Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::get('/projects/{project}/edit', [ProjectController::class, 'edit'])->name('projects.edit');
        Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

        // Knowledge graph (Admin & Analyst only)
        Route::get('/projects/{project}/graph', [ProjectController::class, 'graph'])->name('projects.graph');
        Route::get('/projects/{project}/graph/data', [ProjectController::class, 'graphData'])->name('projects.graph.data');
        Route::get('/assets/{asset}/impact', [GraphController::class, 'impactAnalysis'])->name('assets.impact');
    });

    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');

    // Scans:
    // - Create, Store, Cancel, Retry: Admin & Analyst only.
    Route::middleware('role:admin|analyst')->group(function (): void {
        Route::get('/scans/create', [ScanController::class, 'create'])->name('scans.create');
        Route::post('/scans', [ScanController::class, 'store'])->name('scans.store');
        Route::post('/scans/{scan}/cancel', [ScanController::class, 'cancel'])->name('scans.cancel');
        Route::post('/scans/{scan}/retry', [ScanController::class, 'retry'])->name('scans.retry');
        Route::get('/scans/{scan}/report/generate', [ReportController::class, 'generate'])->name('reports.generate');
    });

    // - Index, Show, Export: Admin, Analyst, Auditor (Client forbidden).
    Route::middleware('role:admin|analyst|auditor')->group(function (): void {
        Route::get('/scans', [ScanController::class, 'index'])->name('scans.index');
        Route::get('/scans/{scan}', [ScanController::class, 'show'])->name('scans.show');
        Route::get('/scans/{scan}/export', [ScanController::class, 'export'])->name('scans.export');
    });

    // Reports (viewing/exporting available to all; generation restricted to admin|analyst above)
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/{report}', [ReportController::class, 'show'])->name('reports.show');
    Route::get('/reports/{report}/pdf', [ReportController::class, 'pdf'])->name('reports.pdf');
    Route::get('/reports/{report}/export/{format}', [ReportController::class, 'export'])
        ->name('reports.export')
        ->where('format', 'pdf|html|json|markdown');
    Route::get('/reports/{report}/download', [ReportController::class, 'download'])->name('reports.download');

    // Security operations: Alerts available to all users (scoped)
    Route::get('/security/alerts', [SecurityController::class, 'alerts'])->name('security.alerts');
    Route::post('/security/alerts/{alert}/acknowledge', [SecurityController::class, 'acknowledge'])
        ->name('security.alerts.acknowledge');

    // Monitoring & Sandbox: Admin & Analyst only
    Route::middleware('role:admin|analyst')->group(function (): void {
        Route::get('/security/monitoring', [SecurityController::class, 'monitoring'])->name('security.monitoring');
        Route::get('/security/sandbox', [SecurityController::class, 'sandbox'])->name('security.sandbox');
        Route::match(['GET', 'POST'], '/security/sandbox/launch', [SecurityController::class, 'launchSandbox'])
            ->name('security.sandbox.launch');
        Route::post('/security/sandbox/{id}/stop', [SecurityController::class, 'stopSandbox'])
            ->name('security.sandbox.stop');
    });

    // OSINT: Admin & Analyst only
    Route::middleware('role:admin|analyst')->group(function (): void {
        Route::get('/osint', [OsintController::class, 'index'])->name('osint.index');
        Route::post('/osint/{target}/run', [OsintController::class, 'run'])->name('osint.run');
        Route::get('/osint/{target}/results', [OsintController::class, 'results'])->name('osint.results');
    });

    // Findings: Admin, Analyst, Auditor (Client forbidden)
    Route::middleware('role:admin|analyst|auditor')->group(function (): void {
        Route::get('/findings', [FindingController::class, 'index'])->name('findings.index');
        Route::get('/findings/{finding}', [FindingController::class, 'show'])->name('findings.show');
    });

    // Remediation-as-Code: Admin & Analyst only
    Route::middleware('role:admin|analyst')->group(function (): void {
        Route::get('/findings/{finding}/remediation', [RemediationController::class, 'show'])->name('remediation.show');
        Route::post('/findings/{finding}/remediation/generate', [RemediationController::class, 'generate'])
            ->name('remediation.generate');
        Route::get('/remediation/{script}/download', [RemediationController::class, 'downloadScript'])
            ->name('remediation.download');
        Route::post('/remediation/{script}/verify', [RemediationController::class, 'verify'])
            ->name('remediation.verify');
        Route::post('/remediation/{script}/apply', [RemediationController::class, 'apply'])
            ->name('remediation.apply');
    });

    // AI Co-pilot chat (accessible to all authenticated users)
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::get('/chat/create', [ChatController::class, 'create'])->name('chat.create');
    Route::post('/chat', [ChatController::class, 'store'])->name('chat.store');
    Route::get('/chat/{session}', [ChatController::class, 'show'])->name('chat.show');
    Route::post('/chat/{session}/messages', [ChatController::class, 'messagesStore'])->name('chat.messages.store');
    Route::delete('/chat/{session}', [ChatController::class, 'destroy'])->name('chat.destroy');

    // Audit and Compliance area (accessible to Admin and Auditor)
    Route::middleware('role:admin|auditor')->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/audit-logs', [AdminController::class, 'auditLogs'])->name('audit-logs');
        Route::get('/system-health', [AdminController::class, 'systemHealth'])->name('system-health');
    });

    // Admin exclusive area (User Management & RBAC)
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/users', [AdminController::class, 'usersIndex'])->name('users.index');
        Route::get('/users/create', [AdminController::class, 'usersCreate'])->name('users.create');
        Route::post('/users', [AdminController::class, 'usersStore'])->name('users.store');
        Route::get('/users/{user}/edit', [AdminController::class, 'usersEdit'])->name('users.edit');
        Route::put('/users/{user}', [AdminController::class, 'usersUpdate'])->name('users.update');
        Route::patch('/users/{user}/deactivate', [AdminController::class, 'usersDeactivate'])->name('users.deactivate');
    });
});
