<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

use App\Http\Controllers\Admin\EscalationController;
use App\Http\Controllers\Admin\AppointmentController;

// Admin routes - protected by auth and is_admin middleware
Route::middleware(['auth', 'is_admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        // Escalations
        Route::get('escalations', [EscalationController::class, 'index'])->name('escalations.index');
        Route::get('escalations/export', [EscalationController::class, 'exportCsv'])->name('escalations.exportCsv');
        Route::get('escalations/{id}', [EscalationController::class, 'show'])->name('escalations.show');
        Route::post('escalations/{id}/status', [EscalationController::class, 'updateStatus'])->name('escalations.updateStatus');
        Route::post('escalations/{id}/resend', [EscalationController::class, 'resend'])->name('escalations.resend');
        Route::post('escalations/bulk', [EscalationController::class, 'bulkAction'])->name('escalations.bulk');

        // Roles
        Route::get('roles', [\App\Http\Controllers\Admin\RoleController::class, 'index'])->name('roles.index');
        Route::post('roles/assign', [\App\Http\Controllers\Admin\RoleController::class, 'assign'])->name('roles.assign');
        Route::post('roles/remove', [\App\Http\Controllers\Admin\RoleController::class, 'remove'])->name('roles.remove');

        // Permissions
        Route::get('permissions', [\App\Http\Controllers\Admin\PermissionController::class, 'index'])->name('permissions.index');
        Route::post('permissions', [\App\Http\Controllers\Admin\PermissionController::class, 'store'])->name('permissions.store');
        Route::delete('permissions/{name}', [\App\Http\Controllers\Admin\PermissionController::class, 'destroy'])->name('permissions.destroy');
        Route::post('permissions/assign', [\App\Http\Controllers\Admin\PermissionController::class, 'assign'])->name('permissions.assign');
        Route::post('permissions/revoke', [\App\Http\Controllers\Admin\PermissionController::class, 'revoke'])->name('permissions.revoke');

        // Invites
        Route::get('invites', [\App\Http\Controllers\Admin\InviteController::class, 'index'])->name('invites.index');
        Route::post('invites', [\App\Http\Controllers\Admin\InviteController::class, 'store'])->name('invites.store');
        Route::patch('invites/{id}/revoke', [\App\Http\Controllers\Admin\InviteController::class, 'revoke'])->name('invites.revoke');
        Route::delete('invites/{id}', [\App\Http\Controllers\Admin\InviteController::class, 'destroy'])->name('invites.destroy');

        // Appointments
        Route::get('appointments', [AppointmentController::class, 'index'])->name('appointments.index');
        Route::get('appointments/export', [AppointmentController::class, 'exportCsv'])->name('appointments.exportCsv');
        Route::get('appointments/{id}', [AppointmentController::class, 'show'])->name('appointments.show');
        Route::post('appointments/{id}/status', [AppointmentController::class, 'updateStatus'])->name('appointments.updateStatus');
        Route::post('appointments/{id}/confirm', [AppointmentController::class, 'confirm'])->name('appointments.confirm');
        Route::post('appointments/bulk', [AppointmentController::class, 'bulkAction'])->name('appointments.bulk');
    });
