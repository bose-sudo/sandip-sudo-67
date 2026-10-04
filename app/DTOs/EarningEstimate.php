<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class EarningEstimate
{
    public function __construct(
        public float $p10,
        public float $p50,
        public float $p90,
        public float $mean,
        public float $min,
        public float $max,
        public int $iterations,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}