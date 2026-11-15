<?php

namespace App\Enums;

/**
 * The six learner categories the school's monitoring sheet counts.
 *
 * The encoding sheet also marks each learner Regular or Irregular. That is
 * stored separately as enrollments.is_irregular, because only Balik-Aral
 * learners can be either one.
 */
enum LearnerType: string
{
    case OldStudent = 'old_student';
    case NewStudent = 'new_student';
    case Als = 'als';
    case BalikAral = 'balik_aral';
    case Repeater = 'repeater';
    case Transferee = 'transferee';

    public function label(): string
    {
        return match ($this) {
            self::OldStudent => 'Old Student',
            self::NewStudent => 'New Student',
            self::Als => 'ALS',
            self::BalikAral => 'Balik-Aral',
            self::Repeater => 'Repeater',
            self::Transferee => 'Transferee',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::OldStudent => 'Continuing DJIHS learner',
            self::NewStudent => 'Grade 10 completer from another school',
            self::Als => 'Alternative Learning System completer',
            self::BalikAral => 'Returning after stopping school',
            self::Repeater => 'Repeating the grade level',
            self::Transferee => 'Moving in from another school',
        };
    }

    /**
     * Whether learners of this type are irregular by default.
     */
    public function defaultIrregular(): bool
    {
        return in_array($this, [self::Repeater, self::Transferee], true);
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $t) => [
            'value' => $t->value,
            'label' => $t->label(),
            'description' => $t->description(),
        ], self::cases());
    }
}
