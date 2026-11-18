<?php

use App\Http\Controllers\GovernanceApprovalController;
use App\Http\Controllers\NexCoreAiController;
use App\Http\Controllers\OrganizationContextController;
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

    Route::middleware('auth')->group(function () {
        Route::get('/me', [SessionController::class, 'me']);
        Route::post('/organization', [SessionController::class, 'selectOrganization']);
        Route::get('/organization/context', [OrganizationContextController::class, 'show'])
            ->middleware('organization.active');
        Route::post('/logout', [SessionController::class, 'logout']);

        Route::prefix('/governance/approvals')->group(function () {
            Route::post('/{approval}/otp', [GovernanceApprovalController::class, 'sendOtp'])->middleware('throttle:3,1');
            Route::post('/{approval}/confirm', [GovernanceApprovalController::class, 'confirm'])->middleware('throttle:5,1');
        });
    });
});

// Authenticated NexCore AI workspace; tenant identity is verified by middleware.
Route::middleware(['auth', 'organization.active'])->get('/ai', fn () => view('ai.chat'));

// Shared NexCore AI gateway: tenant identity comes from server-verified session context.
Route::middleware(['auth', 'organization.active', 'throttle:10,1'])
    ->post('/ai/assist', [NexCoreAiController::class, 'assist']);
