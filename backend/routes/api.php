<?php

use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CardController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\GoalController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ResourceController;
use App\Http\Controllers\Api\TransferController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('health', HealthController::class);
    Route::prefix('auth')->middleware('throttle:auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
    });

    Route::middleware(['jwt.auth', 'throttle:api'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::patch('auth/profile', [AuthController::class, 'updateProfile']);
        Route::put('auth/password', [AuthController::class, 'changePassword']);
        Route::get('dashboard', DashboardController::class);

        foreach (['accounts', 'categories', 'tags', 'transactions', 'cards', 'budgets', 'goals', 'recurrences'] as $resource) {
            Route::get($resource, [ResourceController::class, 'index'])->defaults('resource', $resource);
            Route::post($resource, [ResourceController::class, 'store'])->defaults('resource', $resource);
            Route::get("$resource/{id}", [ResourceController::class, 'show'])->whereNumber('id')->defaults('resource', $resource);
            Route::match(['put', 'patch'], "$resource/{id}", [ResourceController::class, 'update'])->whereNumber('id')->defaults('resource', $resource);
            Route::delete("$resource/{id}", [ResourceController::class, 'destroy'])->whereNumber('id')->defaults('resource', $resource);
            Route::post("$resource/{id}/restore", [ResourceController::class, 'restore'])->whereNumber('id')->defaults('resource', $resource);
        }
        Route::get('transfers', [TransferController::class, 'index']);
        Route::post('transfers', [TransferController::class, 'store']);
        Route::get('transfers/{id}', [TransferController::class, 'show'])->whereNumber('id');
        Route::get('card-purchases', [CardController::class, 'purchases']);
        Route::post('card-purchases', [CardController::class, 'purchase']);
        Route::get('invoices', [CardController::class, 'invoices']);
        Route::post('invoices/{id}/pay', [CardController::class, 'pay'])->whereNumber('id');
        Route::post('goals/{id}/contributions', [GoalController::class, 'contribute'])->whereNumber('id');
        Route::post('transactions/{transaction}/attachments', [AttachmentController::class, 'store'])->whereNumber('transaction');
        Route::get('attachments/{id}/download', [AttachmentController::class, 'download'])->whereNumber('id');
        Route::delete('attachments/{id}', [AttachmentController::class, 'destroy'])->whereNumber('id');
        Route::get('reports', [ReportController::class, 'index']);
        Route::get('reports/export.csv', [ReportController::class, 'csv']);
    });
});
