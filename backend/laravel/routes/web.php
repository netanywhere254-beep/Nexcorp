<?php
use App\Http\Controllers\AccountingIntegrationController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\GovernanceApprovalController;
use App\Http\Controllers\GroupAuthorityController;
use App\Http\Controllers\GroupStaffOtpController;
use App\Http\Controllers\NexCoreAiController;
use App\Http\Controllers\NexispBooksController;
use App\Http\Controllers\NexispCustomerController;
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

// Nexora Group governance staff security is group-scoped, not tenant-scoped.
Route::middleware(['auth','verified'])->prefix('group/staff-security')->group(function(){
 Route::post('/approved-phones',[GroupStaffOtpController::class,'approveNumber'])->middleware('throttle:10,1');
 Route::delete('/approved-phones/{id}',[GroupStaffOtpController::class,'revokeNumber'])->middleware('throttle:10,1');
 Route::post('/otp/send',[GroupStaffOtpController::class,'sendCode'])->middleware('throttle:3,1');
 Route::post('/otp/verify',[GroupStaffOtpController::class,'verifyCode'])->middleware('throttle:6,1');
});
Route::middleware(['auth','verified'])->prefix('group/governance/approvals')->group(function(){
 Route::get('/',[GovernanceApprovalController::class,'index']);
 Route::post('/{approvalId}/decision',[GovernanceApprovalController::class,'decide'])->middleware('throttle:10,1');
});
Route::middleware(['auth','verified'])->prefix('group/governance/authorities')->group(function(){
 Route::delete('/{id}',[GroupAuthorityController::class,'revoke'])->middleware('throttle:5,1');
});

// NexISP customer management is always scoped to the server-selected organization.
Route::middleware(['auth', 'verified', 'organization.active'])
    ->prefix('nexisp/customers')
    ->group(function () {
        Route::get('/', [NexispCustomerController::class, 'index']);
        Route::post('/', [NexispCustomerController::class, 'store'])->middleware('throttle:30,1');
        Route::get('/{id}', [NexispCustomerController::class, 'show'])->whereNumber('id');
        Route::patch('/{id}', [NexispCustomerController::class, 'update'])
            ->whereNumber('id')
            ->middleware('throttle:30,1');
    });

// NexISP Books is the primary ISP bookkeeping system; third-party accounting is optional.
Route::middleware(['auth', 'verified', 'organization.active'])
    ->prefix('nexisp/books')
    ->group(function () {
        Route::get('/overview', [NexispBooksController::class, 'overview']);
        Route::get('/settings', [NexispBooksController::class, 'settings']);
        Route::put('/settings', [NexispBooksController::class, 'updateSettings'])->middleware('throttle:20,1');
        Route::post('/bootstrap', [NexispBooksController::class, 'bootstrap'])->middleware('throttle:5,1');
        Route::get('/accounts', [NexispBooksController::class, 'accounts']);
        Route::get('/journals', [NexispBooksController::class, 'journalEntries']);
        Route::post('/journals', [NexispBooksController::class, 'postJournal'])->middleware('throttle:30,1');
        Route::get('/invoices', [NexispBooksController::class, 'invoices']);
        Route::post('/invoices', [NexispBooksController::class, 'createInvoice'])->middleware('throttle:30,1');
        Route::get('/expenses', [NexispBooksController::class, 'expenses']);
        Route::post('/expenses', [NexispBooksController::class, 'createExpense'])->middleware('throttle:30,1');
        Route::post('/invoices/{invoiceId}/receipts', [NexispBooksController::class, 'issueReceipt'])
            ->whereNumber('invoiceId')->middleware('throttle:20,1');
        Route::get('/invoices/{invoiceId}/export/{format}', [NexispBooksController::class, 'exportInvoice'])
            ->whereNumber('invoiceId')->whereIn('format', ['csv', 'xlsx', 'pdf']);
        Route::get('/receipts/{receiptId}/export/{format}', [NexispBooksController::class, 'exportReceipt'])
            ->whereNumber('receiptId')->whereIn('format', ['csv', 'xlsx', 'pdf']);
    });

// NexISP accounting integrations are organization-scoped and billing-admin only.
Route::middleware(['auth', 'verified', 'organization.active'])
    ->prefix('nexisp/accounting')
    ->group(function () {
        Route::get('/providers', [AccountingIntegrationController::class, 'providers']);
        Route::get('/connections', [AccountingIntegrationController::class, 'connections']);
        Route::put('/connections/{connectionId}/mappings', [AccountingIntegrationController::class, 'saveMappings'])
            ->whereNumber('connectionId')
            ->middleware('throttle:20,1');
        Route::get('/connections/{connectionId}/sync-runs', [AccountingIntegrationController::class, 'syncRuns'])
            ->whereNumber('connectionId');
        Route::post('/connections/{connectionId}/invoices/{invoiceId}/export', [AccountingIntegrationController::class, 'queueInvoiceExport'])
            ->whereNumber('connectionId')
            ->whereNumber('invoiceId')
            ->middleware('throttle:20,1');
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
