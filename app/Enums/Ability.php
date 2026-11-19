<?php

namespace App\Enums;

/**
 * Every permission check in the app goes through one of these.
 * Each case is registered as a Laravel Gate in AppServiceProvider.
 * Which role has which is set on the Roles and Permissions page.
 */
enum Ability: string
{
    case ManageUsers = 'manage-users';
    case ManageRoles = 'manage-roles';
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

    /** What it lets someone do, in plain words, for the Roles and Permissions page. */
    public function label(): string
    {
        return match ($this) {
            self::EncodeEnrollments => 'Encode new enrollments',
            self::ImportEnrollments => 'Import learners from Excel or CSV',
            self::ReviewEnrollments => 'Review and approve enrollments',
            self::ViewEnrollments => 'Search and view learner records',
            self::EditEnrollments => 'Edit learner records, approve change requests and record dropouts',
            self::RequestRevisions => 'Ask for changes to learner records (approved by someone who can edit them)',
            self::ManageDocuments => 'Check off submitted documents',
            self::ManageSections => 'Manage sections and strands, and place learners in sections',
            self::ViewOwnSection => 'Open My Section (their advisory class)',
            self::ViewOwnClasses => 'Open My Classes (the subjects they teach)',
            self::ViewReports => 'See enrollment numbers on the dashboard',
            self::ViewAnalytics => 'Open the Reports page',
            self::ViewMetrics => 'See DepEd indicators on the Reports page',
            self::ManageUsers => 'Manage user accounts',
            self::ManageRoles => 'Manage roles and permissions',
            self::ManageSchoolYears => 'Manage school years and open or close enrollment',
            self::ViewAuditLog => 'View the audit log',
            self::BackupData => 'Download a full backup',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::EncodeEnrollments, self::ImportEnrollments, self::ReviewEnrollments => 'Enrollment',
            self::ViewEnrollments, self::EditEnrollments, self::RequestRevisions, self::ManageDocuments => 'Learner records',
            self::ManageSections => 'Sections',
            self::ViewOwnSection, self::ViewOwnClasses => 'Teaching',
            self::ViewReports, self::ViewAnalytics, self::ViewMetrics => 'Reports',
            self::ManageUsers, self::ManageRoles, self::ManageSchoolYears, self::ViewAuditLog, self::BackupData => 'Administration',
        };
    }

    /** A caution shown beside the permission, where ticking it alone isn't enough. */
    public function note(): ?string
    {
        return match ($this) {
            self::ViewOwnSection => 'Only shows something for a teacher set as a section\'s adviser.',
            self::ViewOwnClasses => 'Only shows something for a teacher assigned to teach a subject.',
            default => null,
        };
    }
}
