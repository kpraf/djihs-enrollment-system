<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\MySectionController;
use App\Http\Controllers\MyStudentsController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SchoolYearController;
use App\Http\Controllers\SectionAssignmentController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\StrandController;
use App\Http\Controllers\StudentLookupController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware('auth')->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // New Enrollment
    Route::middleware('can:encode-enrollments')->group(function () {
        Route::get('enrollments/create', [EnrollmentController::class, 'create'])->name('enrollments.create');
        Route::post('enrollments', [EnrollmentController::class, 'store'])->name('enrollments.store');
        Route::get('students/lookup', StudentLookupController::class)->middleware('throttle:120,1')->name('students.lookup');
    });

    // Editing: direct for registrar/ICT/admin, as a revision request for advisers.
    Route::middleware('can:change-enrollment')->group(function () {
        Route::get('enrollments/{enrollment}/edit', [EnrollmentController::class, 'edit'])->name('enrollments.edit');
        Route::put('enrollments/{enrollment}', [EnrollmentController::class, 'update'])->name('enrollments.update');
    });

    Route::middleware('can:edit-enrollments')->group(function () {
        Route::post('enrollments/{enrollment}/leave', [EnrollmentController::class, 'leave'])->name('enrollments.leave');
        Route::post('enrollments/{enrollment}/restore', [EnrollmentController::class, 'restore'])->name('enrollments.restore');
        Route::post('revisions/{revision}/approve', [ReviewController::class, 'approveRevision'])->name('revisions.approve');
        Route::post('revisions/{revision}/reject', [ReviewController::class, 'rejectRevision'])->name('revisions.reject');
    });

    Route::put('enrollments/{enrollment}/documents', [DocumentController::class, 'update'])
        ->middleware('can:manage-documents')
        ->name('enrollments.documents');

    // Student Management
    Route::middleware('can:view-enrollments')->group(function () {
        Route::get('enrollments', [EnrollmentController::class, 'index'])->name('enrollments.index');
        Route::get('enrollments/{enrollment}', [EnrollmentController::class, 'show'])->whereNumber('enrollment')->name('enrollments.show');
    });

    // Review Enrollments
    Route::middleware('can:review-enrollments')->group(function () {
        Route::get('reviews', [ReviewController::class, 'index'])->name('reviews.index');
        Route::post('reviews/approve-all', [ReviewController::class, 'approveAll'])->name('reviews.approve-all');
        Route::post('reviews/{enrollment}/approve', [ReviewController::class, 'approve'])->name('reviews.approve');
        Route::post('reviews/{enrollment}/reject', [ReviewController::class, 'reject'])->name('reviews.reject');
    });

    // Bulk import from the school's workbook
    Route::middleware('can:import-enrollments')->group(function () {
        Route::get('imports', [ImportController::class, 'create'])->name('imports.create');
        Route::post('imports/preview', [ImportController::class, 'preview'])->name('imports.preview');
        Route::post('imports', [ImportController::class, 'store'])->name('imports.store');
        Route::delete('imports', [ImportController::class, 'destroy'])->name('imports.destroy');
        Route::get('imports/template', [ExportController::class, 'template'])->name('imports.template');
    });

    // Key teacher: strands, sections, subject teachers, assigning students
    Route::middleware('can:manage-sections')->group(function () {
        Route::get('strands', [StrandController::class, 'index'])->name('strands.index');
        Route::post('strands', [StrandController::class, 'store'])->name('strands.store');
        Route::put('strands/{strand}', [StrandController::class, 'update'])->name('strands.update');
        Route::put('strands/{strand}/active', [StrandController::class, 'setActive'])->name('strands.set-active');
        Route::delete('strands/{strand}', [StrandController::class, 'destroy'])->name('strands.destroy');

        Route::get('sections', [SectionController::class, 'index'])->name('sections.index');
        Route::post('sections', [SectionController::class, 'store'])->name('sections.store');
        Route::put('sections/{section}', [SectionController::class, 'update'])->name('sections.update');
        Route::delete('sections/{section}', [SectionController::class, 'destroy'])->name('sections.destroy');
        Route::post('sections/{section}/teachers', [SectionController::class, 'addTeacher'])->name('sections.teachers.store');
        Route::delete('teacher-assignments/{assignment}', [SectionController::class, 'removeTeacher'])->name('sections.teachers.destroy');

        Route::get('section-assignments', [SectionAssignmentController::class, 'index'])->name('section-assignments.index');
        Route::post('section-assignments', [SectionAssignmentController::class, 'assign'])->name('section-assignments.assign');
        Route::post('section-assignments/auto', [SectionAssignmentController::class, 'auto'])->name('section-assignments.auto');
        Route::delete('section-assignments/{enrollment}', [SectionAssignmentController::class, 'unassign'])->name('section-assignments.unassign');
        Route::delete('sections/{section}/learners', [SectionAssignmentController::class, 'clear'])->name('section-assignments.clear');
    });

    // Adviser and subject teacher pages
    Route::get('my-section', [MySectionController::class, 'index'])->middleware('can:view-own-section')->name('my-section.index');
    Route::get('sections/{section}/export/{format}', [MySectionController::class, 'export'])->whereIn('format', ['csv', 'xlsx'])->name('sections.export');
    Route::get('sections/{section}/sf1', [MySectionController::class, 'sf1'])->name('sections.sf1');
    Route::get('sections/{section}/print', [MySectionController::class, 'print'])->name('sections.print');
    Route::middleware('can:view-own-classes')->group(function () {
        Route::get('my-students', [MyStudentsController::class, 'index'])->name('my-students.index');
        Route::get('my-students/{assignment}/export', [MyStudentsController::class, 'export'])->name('my-students.export');
    });

    // Reports
    Route::middleware('can:view-analytics')->group(function () {
        Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
        Route::get('analytics/export', [AnalyticsController::class, 'export'])->name('analytics.export');
    });
    Route::middleware('can:view-metrics')->group(function () {
        Route::get('metrics', [MetricsController::class, 'index'])->name('metrics.index');
        Route::get('metrics/export', [MetricsController::class, 'export'])->name('metrics.export');
    });

    // Academic Year Management
    Route::middleware('can:manage-school-years')->group(function () {
        Route::get('school-years', [SchoolYearController::class, 'index'])->name('school-years.index');
        Route::post('school-years', [SchoolYearController::class, 'store'])->name('school-years.store');
        Route::put('school-years/{schoolYear}', [SchoolYearController::class, 'update'])->name('school-years.update');
        Route::post('school-years/{schoolYear}/activate', [SchoolYearController::class, 'activate'])->name('school-years.activate');
        Route::put('school-years/{schoolYear}/archive', [SchoolYearController::class, 'archive'])->name('school-years.archive');
    });

    // User accounts
    Route::middleware('can:manage-users')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::get('users/export', [ExportController::class, 'users'])->name('users.export');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::put('users/{user}/active', [UserController::class, 'setActive'])->name('users.set-active');
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
    });

    // Audit log and backup
    Route::middleware('can:view-audit-log')->group(function () {
        Route::get('audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');
        Route::get('audit-log/export', [ExportController::class, 'auditLog'])->name('audit-log.export');
    });
    Route::get('backup', [ExportController::class, 'backup'])->middleware('can:backup-data')->name('backup');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
