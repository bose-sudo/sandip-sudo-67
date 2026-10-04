<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\EarningEstimate;
use App\Enums\FearProduct;

final class FearEarningService
{
    /**
     * @param array{min: float, mode: float, max: float}|null $rate
     * @param array{min: float, mode: float, max: float}|null $km
     */
    public function estimate(
        FearProduct $product,
        ?int $seed = null,
        int $days = 25,
        ?array $rate = null,
        ?array $km = null,
        int $iterations = 20_000,
    ): EarningEstimate {
        return (new EvEarningSimulator($seed))->estimate(
            rate: $rate ?? $product->rate(),
            km: $km ?? $product->km(),
            daysMin: $days,
            daysMax: $days,
            iterations: $iterations,
        );
    }
}