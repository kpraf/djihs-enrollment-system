<?php

namespace App\Enums;

/**
 * Larger text for staff who find the default hard to read. The whole
 * interface is sized in rem, so changing the root font size scales
 * everything, including spacing, evenly.
 */
enum TextSize: string
{
    case Normal = 'normal';
    case Large = 'large';
    case ExtraLarge = 'xlarge';

    public const COOKIE = 'text_size';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::Large => 'Large',
            self::ExtraLarge => 'Extra large',
        };
    }

    /**
     * Root font size, as a percentage of the browser default.
     */
    public function percent(): int
    {
        return match ($this) {
            self::Normal => 100,
            self::Large => 115,
            self::ExtraLarge => 130,
        };
    }

    /**
     * @return list<array{value: string, label: string, percent: int}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label(), 'percent' => $s->percent()], self::cases());
    }
}
