<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\StudentLookupController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware('auth')->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::middleware('can:encode-enrollments')->group(function () {
        Route::get('enrollments/create', [EnrollmentController::class, 'create'])->name('enrollments.create');
        Route::post('enrollments', [EnrollmentController::class, 'store'])->name('enrollments.store');
        Route::get('enrollments/{enrollment}/edit', [EnrollmentController::class, 'edit'])->name('enrollments.edit');
        Route::put('enrollments/{enrollment}', [EnrollmentController::class, 'update'])->name('enrollments.update');
        Route::post('enrollments/{enrollment}/cancel', [EnrollmentController::class, 'cancel'])->name('enrollments.cancel');
        Route::post('enrollments/{enrollment}/restore', [EnrollmentController::class, 'restore'])->name('enrollments.restore');
        Route::get('students/lookup', StudentLookupController::class)->middleware('throttle:120,1')->name('students.lookup');
    });

    Route::middleware('can:view-enrollments')->group(function () {
        Route::get('enrollments', [EnrollmentController::class, 'index'])->name('enrollments.index');
        Route::get('enrollments/{enrollment}', [EnrollmentController::class, 'show'])->name('enrollments.show');
    });

    Route::middleware('can:import-enrollments')->group(function () {
        Route::get('imports', [ImportController::class, 'create'])->name('imports.create');
        Route::post('imports/preview', [ImportController::class, 'preview'])->name('imports.preview');
        Route::post('imports', [ImportController::class, 'store'])->name('imports.store');
        Route::delete('imports', [ImportController::class, 'destroy'])->name('imports.destroy');
    });

    Route::middleware('can:manage-users')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::put('users/{user}/active', [UserController::class, 'setActive'])->name('users.set-active');
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
    });

    Route::get('audit-log', [AuditLogController::class, 'index'])
        ->middleware('can:view-audit-log')
        ->name('audit-log.index');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
