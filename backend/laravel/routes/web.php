<?php

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
    });
});

// Shared NexCore AI gateway: tenant identity comes from server-verified session context.
Route::middleware(['auth', 'organization.active', 'throttle:10,1'])
    ->post('/ai/assist', [NexCoreAiController::class, 'assist']);
