<?php

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\BookingLookupController;
use App\Http\Controllers\MpesaCallbackController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware(['tenant.api-token', 'throttle:120,1'])
    ->name('api.v1.')
    ->group(function (): void {
        Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments.index');
        Route::get('/appointments/{appointmentId}', [AppointmentController::class, 'show'])
            ->whereNumber('appointmentId')
            ->name('appointments.show');
    });

Route::get('/bookings/lookup/{bookingReference}', [BookingLookupController::class, 'show'])
    ->where('bookingReference', 'BK-[A-F0-9]{32}')
    ->middleware('throttle:10,1')
    ->name('bookings.lookup');

Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])
    ->name('webhooks.whatsapp.verify');
Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive'])
    ->name('webhooks.whatsapp.receive')
    ->middleware('throttle:60,1');
Route::get('/whatsapp/webhook', [WhatsAppWebhookController::class, 'verify'])
    ->name('whatsapp.webhook.verify')
    ->middleware('throttle:60,1');
Route::post('/whatsapp/webhook', [WhatsAppWebhookController::class, 'handle'])
    ->name('whatsapp.webhook.handle')
    ->middleware('throttle:60,1');
Route::get('/whatsapp/webhook/{hospitalSlug}', [WhatsAppWebhookController::class, 'verify'])
    ->name('tenant.whatsapp.webhook.verify')
    ->middleware('throttle:60,1');
Route::post('/whatsapp/webhook/{hospitalSlug}', [WhatsAppWebhookController::class, 'handle'])
    ->name('tenant.whatsapp.webhook.handle')
    ->middleware('throttle:60,1');
Route::post('/mpesa/callback', [MpesaCallbackController::class, 'handleConfirmation'])
    ->name('mpesa.callback')
    ->middleware(['throttle:120,1', 'safaricom.ip']);
Route::post('/mpesa/callback/{hospitalSlug}', [MpesaCallbackController::class, 'handleConfirmation'])
    ->name('tenant.mpesa.callback')
    ->middleware(['throttle:120,1', 'safaricom.ip']);
Route::post('/payments/confirm/{hospitalSlug}', [MpesaCallbackController::class, 'handleConfirmation'])
    ->name('payments.confirm')
    ->middleware(['throttle:120,1', 'safaricom.ip']);
Route::get('/mpesa/status/{checkoutRequestId}', [MpesaCallbackController::class, 'checkStatus'])
    ->name('mpesa.status')
    ->middleware(['throttle:10,1', 'feature:mpesa']);
