<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware('auth')->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('enrollments', [EnrollmentController::class, 'index'])
        ->middleware('can:view-enrollments')
        ->name('enrollments.index');

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
        Route::post('users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
