<?php

namespace App\Enums;

/**
 * Every permission check in the app goes through one of these.
 * Each case is registered as a Laravel Gate in AppServiceProvider.
 */
enum Ability: string
{
    case ManageUsers = 'manage-users';
    case ManageSchoolYears = 'manage-school-years';
    case ManageSections = 'manage-sections';
    case EncodeEnrollments = 'encode-enrollments';
    case ReviewEnrollments = 'review-enrollments';
    /** Edit learner records directly and change their status. */
    case EditEnrollments = 'edit-enrollments';
    /** Propose edits that someone with EditEnrollments approves. */
    case RequestRevisions = 'request-revisions';
    case ManageDocuments = 'manage-documents';
    case ImportEnrollments = 'import-enrollments';
    case ViewEnrollments = 'view-enrollments';
    case ViewOwnSection = 'view-own-section';
    case ViewOwnClasses = 'view-own-classes';
    case ViewReports = 'view-reports';
    case ViewAnalytics = 'view-analytics';
    case ViewMetrics = 'view-metrics';
    case ViewAuditLog = 'view-audit-log';
    case BackupData = 'backup-data';
}
