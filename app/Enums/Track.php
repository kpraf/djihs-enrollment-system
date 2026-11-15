<?php

namespace App\Enums;

enum Track: string
{
    case Academic = 'academic';
    case Tvl = 'tvl';

    public function label(): string
    {
        return match ($this) {
            self::Academic => 'Academic',
            self::Tvl => 'TVL',
        };
    }
}
