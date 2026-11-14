<?php

namespace App\Enums;

/**
 * Staff roles carried over from the legacy system.
 *
 * What each role may do lives in abilities(), so changing a role's
 * permissions is a one-line edit here instead of a hunt through pages.
 */
enum Role: string
{
    case Admin = 'admin';
    case IctCoordinator = 'ict_coordinator';
    case Registrar = 'registrar';
    case KeyTeacher = 'key_teacher';
    case Adviser = 'adviser';
    case SubjectTeacher = 'subject_teacher';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::IctCoordinator => 'ICT Coordinator',
            self::Registrar => 'Registrar',
            self::KeyTeacher => 'Key Teacher',
            self::Adviser => 'Adviser',
            self::SubjectTeacher => 'Subject Teacher',
        };
    }

    /**
     * Mirrors what each role could do in the legacy system.
     *
     * @return list<Ability>
     */
    public function abilities(): array
    {
        return match ($this) {
            // Everything except the two personal pages, which only make sense
            // for someone who advises a section or teaches a class.
            self::Admin => array_values(array_filter(
                Ability::cases(),
                fn (Ability $a) => ! in_array($a, [Ability::ViewOwnSection, Ability::ViewOwnClasses], true),
            )),
            self::IctCoordinator => [
                Ability::ManageUsers,
                Ability::ManageSchoolYears,
                Ability::EncodeEnrollments,
                Ability::ReviewEnrollments,
                Ability::EditEnrollments,
                Ability::ManageDocuments,
                Ability::ImportEnrollments,
                Ability::ViewEnrollments,
                Ability::ViewReports,
                Ability::ViewAuditLog,
                Ability::BackupData,
            ],
            self::Registrar => [
                Ability::ManageUsers,
                Ability::EncodeEnrollments,
                Ability::ReviewEnrollments,
                Ability::EditEnrollments,
                Ability::ManageDocuments,
                Ability::ImportEnrollments,
                Ability::ViewEnrollments,
                Ability::ViewReports,
                Ability::ViewAnalytics,
                Ability::ViewAuditLog,
            ],
            self::KeyTeacher => [
                Ability::ManageSections,
                Ability::ViewEnrollments,
                Ability::ViewReports,
            ],
            self::Adviser => [
                Ability::EncodeEnrollments,
                Ability::ReviewEnrollments,
                Ability::RequestRevisions,
                Ability::ManageDocuments,
                Ability::ViewEnrollments,
                Ability::ViewOwnSection,
                Ability::ViewReports,
            ],
            self::SubjectTeacher => [
                Ability::ViewOwnClasses,
            ],
        };
    }

    public function can(Ability $ability): bool
    {
        return in_array($ability, $this->abilities(), true);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $r) => ['value' => $r->value, 'label' => $r->label()], self::cases());
    }
}
