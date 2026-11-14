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
     * @return list<Ability>
     */
    public function abilities(): array
    {
        return match ($this) {
            self::Admin => Ability::cases(),
            self::IctCoordinator => [
                Ability::ManageUsers,
                Ability::ManageSchoolSetup,
                Ability::EncodeEnrollments,
                Ability::ImportEnrollments,
                Ability::ViewEnrollments,
                Ability::ViewReports,
                Ability::ViewAuditLog,
            ],
            self::Registrar => [
                Ability::EncodeEnrollments,
                Ability::ImportEnrollments,
                Ability::ViewEnrollments,
                Ability::ViewReports,
                Ability::ViewAuditLog,
            ],
            self::KeyTeacher => [
                Ability::ManageSchoolSetup,
                Ability::ViewEnrollments,
                Ability::ViewReports,
            ],
            self::Adviser => [
                Ability::EncodeEnrollments,
                Ability::ViewEnrollments,
                Ability::ViewReports,
            ],
            self::SubjectTeacher => [
                Ability::ViewReports,
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
