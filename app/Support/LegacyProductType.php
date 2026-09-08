<?php
declare(strict_types=1);
namespace App\Support;

final class LegacyProductType
{
    public const MAP = [1=>'E-Rickshaw (L3)', 2=>'E-Auto (L5)', 3=>'E-Two Wheeler', 4=>'Battery'];

    public static function name(int|string|null $id): ?string
    {
        return self::MAP[(int) $id] ?? null;
    }

    public static function id(?string $name): ?int
    {
        return array_search($name, self::MAP, true) ?: null;
    }
}