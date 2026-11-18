<?php

use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\NexCoreAiController;
use App\Http\Controllers\OrganizationContextController;
use App\Http\Controllers\PasswordRecoveryController;
use App\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'application' => 'Nexora primary platform',
        'architecture' => 'laravel-mysql',
    ]);
});

// Cookie-based session authentication uses Laravel's web middleware and CSRF protection.
Route::prefix('auth')->group(function () {
    Route::post('/register', [SessionController::class, 'register']);
    Route::post('/login', [SessionController::class, 'login']);
    Route::post('/forgot-password', [PasswordRecoveryController::class, 'sendLink'])->middleware('throttle:3,1');
    Route::get('/reset-password/{token}', [PasswordRecoveryController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [PasswordRecoveryController::class, 'reset'])->middleware('throttle:5,1');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])->name('verification.verify');

    Route::middleware('auth')->group(function () {
        Route::get('/me', [SessionController::class, 'me']);
        Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:6,1')->name('verification.send');
        Route::post('/organization', [SessionController::class, 'selectOrganization']);
        Route::get('/organization/context', [OrganizationContextController::class, 'show'])
            ->middleware('organization.active');
        Route::post('/logout', [SessionController::class, 'logout']);
    });
});

// NexCore AI requires both a verified account and a server-verified tenant context.
Route::middleware(['auth', 'verified', 'organization.active'])->get('/ai', fn () => view('ai.chat'));
Route::middleware(['auth', 'verified', 'organization.active', 'throttle:10,1'])
    ->post('/ai/assist', [NexCoreAiController::class, 'assist']);
