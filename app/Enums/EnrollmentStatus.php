<?php

namespace App\Enums;

enum EnrollmentStatus: string
{
    case Enrolled = 'enrolled';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Enrolled => 'Enrolled',
            self::Cancelled => 'Cancelled',
        };
    }
}
