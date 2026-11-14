<?php

namespace App\Enums;

enum GradeLevel: int
{
    case Grade7 = 7;
    case Grade8 = 8;
    case Grade9 = 9;
    case Grade10 = 10;
    case Grade11 = 11;
    case Grade12 = 12;

    public function label(): string
    {
        return 'Grade '.$this->value;
    }

    public function isSeniorHigh(): bool
    {
        return $this->value >= 11;
    }

    /**
     * @return list<self>
     */
    public static function seniorHigh(): array
    {
        return [self::Grade11, self::Grade12];
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $g) => ['value' => $g->value, 'label' => $g->label()], self::cases());
    }
}
