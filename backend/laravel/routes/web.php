<?php
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\NexCoreAiController;
use App\Http\Controllers\OrganizationContextController;
use App\Http\Controllers\OrganizationStaffOtpController;
use App\Http\Controllers\PasswordRecoveryController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\TraceCaseController;
use App\Http\Controllers\TraceProfileController;
use App\Http\Controllers\TracePhoneVerificationController;
use App\Http\Controllers\UserPhoneVerificationController;
use Illuminate\Support\Facades\Route;
Route::get('/',fn()=>view('welcome'));
Route::get('/health',fn()=>response()->json(['status'=>'ok','application'=>'Nexora primary platform','architecture'=>'laravel-mysql']));
Route::prefix('auth')->group(function(){
 Route::post('/register',[SessionController::class,'register']); Route::post('/login',[SessionController::class,'login']);
 Route::post('/forgot-password',[PasswordRecoveryController::class,'sendLink'])->middleware('throttle:3,1');
 Route::get('/reset-password/{token}',[PasswordRecoveryController::class,'showResetForm'])->name('password.reset');
 Route::post('/reset-password',[PasswordRecoveryController::class,'reset'])->middleware('throttle:5,1');
 Route::get('/email/verification-required',fn()=>response()->json(['message'=>'Verify your email address to access your workspace.']))->middleware('auth')->name('verification.notice');
 Route::get('/email/verify/{id}/{hash}',[EmailVerificationController::class,'verify'])->middleware(['signed','throttle:6,1'])->name('verification.verify');
 Route::middleware('auth')->group(function(){
  Route::get('/me',[SessionController::class,'me']);
  Route::post('/email/verification-notification',[EmailVerificationController::class,'resend'])->middleware('throttle:6,1')->name('verification.send');
  Route::post('/organization',[SessionController::class,'selectOrganization']);
  Route::get('/organization/context',[OrganizationContextController::class,'show'])->middleware('organization.active');
  Route::post('/logout',[SessionController::class,'logout']);
 });
});
Route::middleware(['auth','verified','organization.active'])->get('/ai',fn()=>view('ai.chat'));
Route::middleware(['auth','verified','organization.active','throttle:10,1'])->post('/ai/assist',[NexCoreAiController::class,'assist']);
// Public account phone verification is independent of staff and Trace & Find OTP.
Route::middleware(['auth', 'verified'])->prefix('account/phone')->group(function () {
    Route::get('/status', [UserPhoneVerificationController::class, 'status']);
    Route::post('/send-code', [UserPhoneVerificationController::class, 'send'])->middleware('throttle:3,1');
    Route::post('/verify-code', [UserPhoneVerificationController::class, 'verify'])->middleware('throttle:6,1');
});

// Nexora organisation staff allow-list, separate from Loopza family phone verification.
Route::middleware(['auth','verified','organization.active'])->prefix('organization/staff-security')->group(function(){
 Route::post('/approved-phones',[OrganizationStaffOtpController::class,'approveNumber'])->middleware('throttle:10,1');
 Route::delete('/approved-phones/{id}',[OrganizationStaffOtpController::class,'revokeNumber'])->middleware('throttle:10,1');
 Route::post('/otp/send',[OrganizationStaffOtpController::class,'sendCode'])->middleware('throttle:3,1');
 Route::post('/otp/verify',[OrganizationStaffOtpController::class,'verifyCode'])->middleware('throttle:6,1');
});
Route::middleware(['auth','verified'])->prefix('trace-find')->group(function(){
 Route::post('/profile',[TraceProfileController::class,'store'])->middleware('throttle:3,1');
 Route::post('/phone/send-code',[TracePhoneVerificationController::class,'send'])->middleware('throttle:3,1');
 Route::post('/phone/verify-code',[TracePhoneVerificationController::class,'verify'])->middleware('throttle:6,1');
 Route::post('/cases',[TraceCaseController::class,'store'])->middleware('throttle:5,1');
});
