<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\JoinController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectSummaryController;
use App\Http\Controllers\Api\RoleController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/organizations', [OrganizationController::class, 'index']);
    Route::post('/organizations', [OrganizationController::class, 'store']);
    Route::post('/organizations/{organization}/switch', [OrganizationController::class, 'switch'])
        ->whereNumber('organization');

    // Accepting an invitation (token from the email link), from any current organization.
    Route::get('/join/{token}', [JoinController::class, 'show']);
    Route::post('/join/{token}', [JoinController::class, 'store'])->middleware('throttle:20,1');

    // Everything below acts on the current organization.
    Route::middleware('organization')->group(function () {
        Route::get('/members', [MemberController::class, 'index']);
        Route::patch('/members/{member}', [MemberController::class, 'update'])->whereNumber('member');
        Route::delete('/members/{member}', [MemberController::class, 'destroy'])->whereNumber('member');

        Route::get('/invitations', [InvitationController::class, 'index']);
        Route::post('/invitations', [InvitationController::class, 'store'])->middleware('throttle:20,1');
        Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy'])->whereNumber('invitation');

        Route::get('/permissions', [PermissionController::class, 'index']);
        Route::apiResource('roles', RoleController::class)->except('show')->whereNumber('role');

        Route::apiResource('projects', ProjectController::class)->whereNumber('project');

        Route::prefix('projects/{project}')->whereNumber(['project', 'summary'])->scopeBindings()->group(function () {
            Route::get('/summary', [ProjectSummaryController::class, 'show']);
            Route::post('/summary', [ProjectSummaryController::class, 'store'])->middleware('throttle:ai');
            Route::post('/summaries/{summary}/approve', [ProjectSummaryController::class, 'approve']);
            Route::post('/summaries/{summary}/reject', [ProjectSummaryController::class, 'reject']);
        });
    });
});
