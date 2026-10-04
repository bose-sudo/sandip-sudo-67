<?php

declare(strict_types=1);

namespace App\Enums;

enum FearProduct: string
{
    case L3 = 'L3';
    case L5 = 'L5';
    case L5_LITE = 'L5_LITE';
    case S6 = '6S';
    case BATTERY = 'BATTERY';

    /** @return array{min: float, mode: float, max: float} */
    public function rate(): array
    {
        return match ($this) {
            self::L3, self::L5_LITE => self::range(7, 12),
            self::L5                => self::range(12, 18),
            self::S6                => self::range(15, 20),
            self::BATTERY           => self::range(0, 0),
        };
    }

    /** @return array{min: float, mode: float, max: float} */
    public function km(): array
    {
        return match ($this) {
            self::L3, self::L5_LITE => self::range(75, 100),
            self::L5                => self::range(75, 120),
            self::S6                => self::range(100, 130),
            self::BATTERY           => self::range(0, 0),
        };
    }

    public function hasEarningModel(): bool
    {
        return $this !== self::BATTERY;
    }

    /** @return array{min: float, mode: float, max: float} */
    private static function range(float $min, float $max): array
    {
        return ['min' => $min, 'mode' => ($min + $max) / 2, 'max' => $max];
    }
}