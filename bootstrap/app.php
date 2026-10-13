<?php

use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureUserIsDoctor;
use App\Http\Middleware\EnsureUserIsHospitalAdmin;
use App\Http\Middleware\EnsureUserIsPatient;
use App\Http\Middleware\ResolveHospital;
use App\Http\Middleware\RestrictMpesaCallbackToSafaricom;
use App\Http\Middleware\SecurityHeaders;
use App\Models\Hospital;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

require_once __DIR__.'/../app/Helpers/hospital.php';

$application = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(
            append: [ResolveHospital::class, SubstituteBindings::class, SecurityHeaders::class],
            remove: [SubstituteBindings::class],
        );
        $middleware->api(
            append: [ResolveHospital::class, SubstituteBindings::class],
            remove: [SubstituteBindings::class],
        );
        $middleware->alias([
            'doctor' => EnsureUserIsDoctor::class,
            'hospital.admin' => EnsureUserIsHospitalAdmin::class,
            'patient' => EnsureUserIsPatient::class,
            'superadmin' => EnsureSuperAdmin::class,
            'feature' => EnsureFeatureEnabled::class,
            'safaricom.ip' => RestrictMpesaCallbackToSafaricom::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'webhooks/whatsapp',
            'mpesa/callback',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

$application->bind(Hospital::class, fn ($app) => $app->bound('currentHospital')
    ? $app->make('currentHospital')
    : new Hospital);

return $application;
