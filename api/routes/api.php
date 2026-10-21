<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AiUsageController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\JoinController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ProjectChatController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectSummaryController;
use App\Http\Controllers\Api\ProjectWritingHelpController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\TwoFactorChallengeController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Middleware\VerifyStripeWebhook;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
// Each group has its own counter (the prefix), so failed logins don't use up resets.
Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:6,1,password-reset');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:6,1,password-reset');
// The second step of login or reset when two-factor is on (the challenge they returned).
Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->middleware('throttle:6,1,two-factor');

// From Stripe, not the app: no user, authenticated by the payload signature.
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])->middleware(['throttle:120,1', VerifyStripeWebhook::class]);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // The link from the verification email (Nuxt posts it here), and a new one.
    Route::post('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->whereNumber('id')->middleware(['signed:relative', 'throttle:6,1,verify-email'])->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])->middleware('throttle:6,1,verify-email');

    // The user's own account, whatever the current organization.
    Route::patch('/account', [AccountController::class, 'update']);
    Route::put('/account/email', [AccountController::class, 'updateEmail'])->middleware('throttle:6,1,account');
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->middleware('throttle:6,1,account');
    Route::delete('/account', [AccountController::class, 'destroy'])->middleware('throttle:6,1,account');
    Route::post('/account/two-factor', [TwoFactorController::class, 'store'])->middleware('throttle:6,1,account');
    Route::post('/account/two-factor/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:6,1,account');
    Route::post('/account/two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->middleware('throttle:6,1,account');
    Route::delete('/account/two-factor', [TwoFactorController::class, 'destroy'])->middleware('throttle:6,1,account');
    Route::get('/account/devices', [DeviceController::class, 'index']);
    Route::delete('/account/devices/{device}', [DeviceController::class, 'destroy'])->whereNumber('device');

    Route::get('/organizations', [OrganizationController::class, 'index']);
    Route::post('/organizations', [OrganizationController::class, 'store']);
    Route::post('/organizations/{organization}/switch', [OrganizationController::class, 'switch'])
        ->whereNumber('organization');

    // Accepting an invitation (token from the email link), from any current organization.
    Route::get('/join/{token}', [JoinController::class, 'show']);
    Route::post('/join/{token}', [JoinController::class, 'store'])->middleware(['throttle:20,1', 'verified']);

    // Everything below acts on the current organization. Features that reach other
    // people or cost money also need a verified email ('verified').
    Route::middleware('organization')->group(function () {
        Route::get('/members', [MemberController::class, 'index']);
        Route::patch('/members/{member}', [MemberController::class, 'update'])->whereNumber('member');
        Route::delete('/members/{member}', [MemberController::class, 'destroy'])->whereNumber('member');

        Route::get('/invitations', [InvitationController::class, 'index']);
        Route::post('/invitations', [InvitationController::class, 'store'])->middleware(['throttle:20,1', 'verified']);
        Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy'])->whereNumber('invitation');

        Route::get('/permissions', [PermissionController::class, 'index']);
        Route::apiResource('roles', RoleController::class)->except('show')->whereNumber('role');

        Route::get('/audit-logs', [AuditLogController::class, 'index']);
        Route::get('/audit-log/actions', [AuditLogController::class, 'actions']);

        Route::get('/ai-usage', [AiUsageController::class, 'index']);
        Route::put('/ai-budget', [AiUsageController::class, 'updateBudget'])->middleware('throttle:20,1');

        Route::get('/billing', [BillingController::class, 'show']);
        // These call Stripe: limited per user and per organization (AppServiceProvider).
        Route::post('/billing/checkout', [BillingController::class, 'checkout'])->middleware(['verified', 'throttle:stripe']);
        Route::post('/billing/credits/checkout', [BillingController::class, 'creditsCheckout'])->middleware(['verified', 'throttle:stripe']);
        Route::post('/billing/portal', [BillingController::class, 'portal'])->middleware(['verified', 'throttle:stripe']);
        Route::get('/billing/invoices', [BillingController::class, 'invoices'])->middleware('throttle:stripe');

        Route::apiResource('projects', ProjectController::class)->whereNumber('project');

        // Streamed (text/event-stream); see AiEventStream.
        Route::post('/projects/writing-help', [ProjectWritingHelpController::class, 'store'])->middleware(['verified', 'throttle:ai']);
        Route::post('/projects/{project}/writing-help', [ProjectWritingHelpController::class, 'update'])
            ->whereNumber('project')->middleware(['verified', 'throttle:ai']);

        Route::prefix('projects/{project}')->whereNumber(['project', 'summary'])->scopeBindings()->group(function () {
            Route::get('/summary', [ProjectSummaryController::class, 'show']);
            Route::post('/summary', [ProjectSummaryController::class, 'store'])->middleware(['verified', 'throttle:ai']);
            Route::post('/summaries/{summary}/approve', [ProjectSummaryController::class, 'approve']);
            Route::post('/summaries/{summary}/reject', [ProjectSummaryController::class, 'reject']);

            Route::get('/chat', [ProjectChatController::class, 'index']);
            Route::post('/chat', [ProjectChatController::class, 'store'])->middleware(['verified', 'throttle:ai']);
            Route::delete('/chat', [ProjectChatController::class, 'destroy']);
        });
    });
});
