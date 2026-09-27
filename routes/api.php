<?php

use App\Http\Controllers\MpesaCallbackController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

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
    ->middleware('throttle:120,1');
Route::post('/mpesa/callback/{hospitalSlug}', [MpesaCallbackController::class, 'handleConfirmation'])
    ->name('tenant.mpesa.callback')
    ->middleware('throttle:120,1');
Route::get('/mpesa/status/{checkoutRequestId}', [MpesaCallbackController::class, 'checkStatus'])
    ->name('mpesa.status')
    ->middleware(['throttle:10,1', 'feature:mpesa']);
