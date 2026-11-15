<?php

namespace App\Enums;

/**
 * Pending: encoded on the form, waiting for review.
 * Enrolled: approved (or imported from the school's sheet). Only these count in reports.
 * Rejected: refused at review; can be corrected and submitted again.
 * Cancelled, Dropped, Transferred out: the learner left after enrolling.
 */
enum EnrollmentStatus: string
{
    case Pending = 'pending';
    case Enrolled = 'enrolled';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Dropped = 'dropped';
    case TransferredOut = 'transferred_out';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Enrolled => 'Enrolled',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
            self::Dropped => 'Dropped',
            self::TransferredOut => 'Transferred Out',
        };
    }

    /**
     * Statuses a learner can be moved to from "enrolled" on their record.
     *
     * @return list<self>
     */
    public static function leaving(): array
    {
        return [self::Cancelled, self::Dropped, self::TransferredOut];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
