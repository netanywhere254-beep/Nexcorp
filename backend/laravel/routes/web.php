<?php

use App\Http\Controllers\OrganizationContextController;
use App\Http\Controllers\SessionController;
use App\Http\Middleware\RequireActiveOrganization;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'application' => 'Nexora primary platform',
        'architecture' => 'laravel-mysql',
    ]);
});

// Session authentication is intentionally cookie-based and protected by
// Laravel's web middleware, including CSRF protection.
Route::prefix('auth')->group(function () {
    Route::post('/login', [SessionController::class, 'login']);
    Route::middleware('auth')->group(function () {
        Route::get('/me', [SessionController::class, 'me']);
        Route::post('/organization', [SessionController::class, 'selectOrganization']);
        Route::post('/logout', [SessionController::class, 'logout']);
    });
});

// All organization-scoped routes must use the server-verified tenant context.
// Controllers must never trust an organization ID supplied in request data.
Route::middleware(['auth', RequireActiveOrganization::class])
    ->prefix('organization')
    ->group(function () {
        Route::get('/context', [OrganizationContextController::class, 'show']);
    });
