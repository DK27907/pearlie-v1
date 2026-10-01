<?php

use App\Http\Controllers\Admin\DoctorController;
use App\Http\Controllers\Auth\DoctorSetupController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\Doctor;
use App\Http\Controllers\Doctor\EscalationController as DoctorEscalationController;
use App\Http\Controllers\HospitalInvitationController;
use App\Http\Controllers\HospitalOnboardingController;
use App\Http\Controllers\MarketingDemoController;
use App\Http\Controllers\MarketingHomeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SuperAdmin\DashboardController as SuperAdminDashboardController;
use App\Http\Controllers\SuperAdmin\HospitalController as SuperAdminHospitalController;
use App\Http\Controllers\TenantHomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MarketingHomeController::class, 'index'])->name('home');
Route::post('/demo-requests', [MarketingDemoController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('marketing.demo.store');

Route::get('/pearlie', [ChatController::class, 'index'])->name('pearlie.index');
Route::post('/pearlie/chat', [ChatController::class, 'chat'])
    ->middleware('throttle:30,1')
    ->name('pearlie.chat');
Route::get('/h/{slug}', [TenantHomeController::class, 'index'])->name('tenant.home');
Route::get('/h/{slug}/chat', [ChatController::class, 'index'])->name('tenant.chat.page');
Route::post('/h/{slug}/chat', [ChatController::class, 'chat'])
    ->middleware('throttle:30,1')
    ->name('tenant.chat.post');
Route::post('/h/{slug}/chat/reset', [ChatController::class, 'reset'])
    ->middleware('throttle:30,1')
    ->name('tenant.chat.reset');
Route::get('/chat/{slug}', [ChatController::class, 'index'])->name('tenant.chat.legacy-page');
Route::post('/chat/{slug}', [ChatController::class, 'chat'])
    ->middleware('throttle:30,1')
    ->name('tenant.chat.short');
Route::get('/hospital/{slug}/invitation/{token}', [HospitalInvitationController::class, 'show'])
    ->middleware('signed')
    ->name('hospital.invitation.setup');
Route::post('/hospital/{slug}/invitation/{token}', [HospitalInvitationController::class, 'store'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('hospital.invitation.store');

Route::middleware('guest')->group(function (): void {
    Route::get('/doctor/setup/{token}', [DoctorSetupController::class, 'show'])
        ->name('doctor.setup');
    Route::post('/doctor/setup/{token}', [DoctorSetupController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('doctor.setup.store');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::get('/settings/profile', [ProfileController::class, 'edit'])->name('settings.profile');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'doctor', 'feature:doctors'])
    ->prefix('doctor')
    ->name('doctor.')
    ->group(function () {
        Route::get('/dashboard', [Doctor\DashboardController::class, 'index'])->name('dashboard');
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile');
        Route::get('/appointments', [Doctor\DashboardController::class, 'appointments'])->name('appointments');
        Route::get('/appointments/{id}', [Doctor\DashboardController::class, 'appointmentDetails'])->name('appointments.show');
        Route::post('/appointments/{id}/confirm', [Doctor\DashboardController::class, 'confirmAppointment'])->name('appointments.confirm');
        Route::post('/appointments/{id}/complete', [Doctor\DashboardController::class, 'completeAppointment'])->name('appointments.complete');
        Route::post('/appointments/{id}/no-show', [Doctor\DashboardController::class, 'markNoShow'])->name('appointments.no-show');
        Route::get('/availability', [Doctor\DashboardController::class, 'availability'])->name('availability');
        Route::post('/availability', [Doctor\DashboardController::class, 'updateAvailability'])->name('availability.update');
        Route::post('/unavailable', [Doctor\DashboardController::class, 'markUnavailable'])->name('unavailable.store');
        Route::delete('/unavailable/{id}', [Doctor\DashboardController::class, 'removeUnavailable'])->name('unavailable.destroy');
        Route::get('/escalations', [DoctorEscalationController::class, 'index'])->middleware('feature:escalation')->name('escalations.index');
        Route::get('/escalations/{id}', [DoctorEscalationController::class, 'show'])->middleware('feature:escalation')->name('escalations.show');
        Route::post('/escalations/{id}/claim', [DoctorEscalationController::class, 'claim'])->middleware('feature:escalation')->name('escalations.claim');
        Route::post('/escalations/{id}/resolve', [DoctorEscalationController::class, 'resolve'])->middleware('feature:escalation')->name('escalations.resolve');
        Route::post('/escalations/{id}/reply', [DoctorEscalationController::class, 'reply'])->middleware('feature:escalation')->name('escalations.reply');
    });

Route::middleware(['auth', 'hospital.admin'])
    ->prefix('hospital')
    ->name('hospital.')
    ->group(function (): void {
        Route::get('/onboarding', [HospitalOnboardingController::class, 'show'])->name('onboarding');
        Route::put('/onboarding', [HospitalOnboardingController::class, 'update'])->name('onboarding.update');
    });

Route::middleware(['auth', 'superadmin'])
    ->prefix('superadmin')
    ->name('superadmin.')
    ->group(function (): void {
        Route::get('/', fn () => redirect()->route('superadmin.dashboard'))->name('home');
        Route::get('/dashboard', [SuperAdminDashboardController::class, 'index'])->name('dashboard');
        Route::resource('hospitals', SuperAdminHospitalController::class);
        Route::post('hospitals/{hospital}/suspend', [SuperAdminHospitalController::class, 'suspend'])
            ->name('hospitals.suspend');
        Route::post('hospitals/{hospital}/activate', [SuperAdminHospitalController::class, 'activate'])
            ->name('hospitals.activate');
        Route::post('hospitals/{hospital}/invite-admin', [SuperAdminHospitalController::class, 'inviteAdmin'])
            ->name('hospitals.invite-admin');
        Route::post('hospitals/{hospital}/impersonate', [SuperAdminHospitalController::class, 'impersonate'])
            ->name('hospitals.impersonate');
        Route::post('stop-impersonating', [SuperAdminHospitalController::class, 'stopImpersonating'])
            ->name('stop-impersonating');
    });

require __DIR__.'/auth.php';

use App\Http\Controllers\Admin\AppointmentController;
use App\Http\Controllers\Admin\ConversationController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EscalationController;
use App\Http\Controllers\Admin\InviteController;
use App\Http\Controllers\Admin\KnowledgeBaseController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\MpesaCallbackController;
use App\Http\Controllers\WhatsAppWebhookController;

// Provider callbacks are also available without the /api prefix for deployments
// that expose web routes directly to external providers.
Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])
    ->middleware('throttle:60,1');
Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive'])
    ->middleware('throttle:60,1');
Route::post('/mpesa/callback', [MpesaCallbackController::class, 'handleConfirmation'])
    ->middleware(['safaricom.ip', 'throttle:120,1'])
    ->name('mpesa.callback.legacy');

// Admin routes - protected by auth and is_admin middleware
Route::middleware(['auth', 'hospital.admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::resource('doctors', DoctorController::class)->except('show')->middleware('feature:doctors');
        Route::post('doctors/{doctor}/invite', [DoctorController::class, 'invite'])
            ->name('doctors.invite');
        Route::get('services', [KnowledgeBaseController::class, 'services'])->name('services.index');
        Route::get('services/create', [KnowledgeBaseController::class, 'serviceCreate'])->name('services.create');
        Route::post('services', [KnowledgeBaseController::class, 'serviceStore'])->name('services.store');
        Route::get('services/{knowledgeBase}/edit', [KnowledgeBaseController::class, 'serviceEdit'])->name('services.edit');
        Route::put('services/{knowledgeBase}', [KnowledgeBaseController::class, 'serviceUpdate'])->name('services.update');
        Route::patch('services/{knowledgeBase}', [KnowledgeBaseController::class, 'serviceUpdate']);
        Route::delete('services/{knowledgeBase}', [KnowledgeBaseController::class, 'serviceDestroy'])->name('services.destroy');
        Route::get('knowledge-base', [KnowledgeBaseController::class, 'index'])->name('knowledge-base.index');
        Route::get('knowledge-base/create', [KnowledgeBaseController::class, 'create'])->name('knowledge-base.create');
        Route::post('knowledge-base', [KnowledgeBaseController::class, 'store'])->name('knowledge-base.store');
        Route::get('knowledge-base/{knowledgeBase}/edit', [KnowledgeBaseController::class, 'edit'])->name('knowledge-base.edit');
        Route::put('knowledge-base/{knowledgeBase}', [KnowledgeBaseController::class, 'update'])->name('knowledge-base.update');
        Route::patch('knowledge-base/{knowledgeBase}', [KnowledgeBaseController::class, 'update']);
        Route::delete('knowledge-base/{knowledgeBase}', [KnowledgeBaseController::class, 'destroy'])->name('knowledge-base.destroy');
        Route::get('conversations', [ConversationController::class, 'index'])->name('conversations.index');
        Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/export', [ReportController::class, 'exportCsv'])->name('reports.export');
        Route::get('settings', [HospitalOnboardingController::class, 'adminSettings'])->name('settings');
        Route::put('settings', [HospitalOnboardingController::class, 'update'])->name('settings.update');

        // Escalations
        Route::get('escalations', [EscalationController::class, 'index'])->middleware('feature:escalation')->name('escalations.index');
        Route::get('escalations/export', [EscalationController::class, 'exportCsv'])->middleware('feature:escalation')->name('escalations.exportCsv');
        Route::post('escalations/{id}/claim', [EscalationController::class, 'claim'])->middleware('feature:escalation')->name('escalations.claim');
        Route::post('escalations/{id}/resolve', [EscalationController::class, 'resolve'])->middleware('feature:escalation')->name('escalations.resolve');
        Route::post('escalations/{id}/reply', [EscalationController::class, 'reply'])->middleware('feature:escalation')->name('escalations.reply');
        Route::get('escalations/{id}', [EscalationController::class, 'show'])->middleware('feature:escalation')->name('escalations.show');
        Route::post('escalations/{id}/status', [EscalationController::class, 'updateStatus'])->middleware('feature:escalation')->name('escalations.updateStatus');
        Route::post('escalations/{id}/resend', [EscalationController::class, 'resend'])->middleware('feature:escalation')->name('escalations.resend');
        Route::post('escalations/bulk', [EscalationController::class, 'bulkAction'])->middleware('feature:escalation')->name('escalations.bulk');

        // Roles
        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::post('roles/assign', [RoleController::class, 'assign'])->name('roles.assign');
        Route::post('roles/remove', [RoleController::class, 'remove'])->name('roles.remove');

        // Permissions
        Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');
        Route::post('permissions', [PermissionController::class, 'store'])->name('permissions.store');
        Route::delete('permissions/{name}', [PermissionController::class, 'destroy'])->name('permissions.destroy');
        Route::post('permissions/assign', [PermissionController::class, 'assign'])->name('permissions.assign');
        Route::post('permissions/revoke', [PermissionController::class, 'revoke'])->name('permissions.revoke');

        // Invites
        Route::get('invites', [InviteController::class, 'index'])->name('invites.index');
        Route::post('invites', [InviteController::class, 'store'])->name('invites.store');
        Route::patch('invites/{id}/revoke', [InviteController::class, 'revoke'])->name('invites.revoke');
        Route::delete('invites/{id}', [InviteController::class, 'destroy'])->name('invites.destroy');

        // Appointments
        Route::get('appointments', [AppointmentController::class, 'index'])->middleware('feature:booking')->name('appointments.index');
        Route::get('appointments/export', [AppointmentController::class, 'exportCsv'])->middleware('feature:booking')->name('appointments.exportCsv');
        Route::get('appointments/{id}', [AppointmentController::class, 'show'])->middleware('feature:booking')->name('appointments.show');
        Route::post('appointments/{id}/status', [AppointmentController::class, 'updateStatus'])->middleware('feature:booking')->name('appointments.updateStatus');
        Route::post('appointments/{id}/confirm', [AppointmentController::class, 'confirm'])->middleware('feature:booking')->name('appointments.confirm');
        Route::post('appointments/{id}/no-show', [AppointmentController::class, 'markNoShow'])->middleware('feature:booking')->name('appointments.no-show');
        Route::post('appointments/bulk', [AppointmentController::class, 'bulkAction'])->middleware('feature:booking')->name('appointments.bulk');
    });
