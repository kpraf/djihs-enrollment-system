<?php

namespace App\Enums;

/**
 * Every permission check in the app goes through one of these.
 * Each case is registered as a Laravel Gate in AppServiceProvider.
 */
enum Ability: string
{
    case ManageUsers = 'manage-users';
    case ManageSchoolSetup = 'manage-school-setup';
    case EncodeEnrollments = 'encode-enrollments';
    case ImportEnrollments = 'import-enrollments';
    case ViewEnrollments = 'view-enrollments';
    case ViewReports = 'view-reports';
    case ViewAuditLog = 'view-audit-log';
}
