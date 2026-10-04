<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\EarningEstimate;
use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class EvEarningSimulator
{
    private const PRECISION = 1_000_000_000;

    private Randomizer $rng;

    public function __construct(?int $seed = null)
    {
        $this->rng = new Randomizer(new Mt19937($seed));
    }

    /**
     * @param array{min: float, mode: float, max: float} $rate
     * @param array{min: float, mode: float, max: float} $km
     */
    public function estimate(
        array $rate,
        array $km,
        int $daysMin = 25,
        int $daysMax = 25,
        int $iterations = 20_000,
    ): EarningEstimate {
        $this->validate($rate, 'rate');
        $this->validate($km, 'km');

        if ($daysMin < 1 || $daysMax < $daysMin || $daysMax > 31) {
            throw new InvalidArgumentException('Invalid working days range.');
        }
        if ($iterations < 100 || $iterations > 200_000) {
            throw new InvalidArgumentException('Iterations must be between 100 and 200000.');
        }

        $months = [];

        for ($i = 0; $i < $iterations; $i++) {
            $days = $this->rng->getInt($daysMin, $daysMax);
            $months[] = $this->simulateMonth($rate, $km, $days);
        }

        sort($months);

        return new EarningEstimate(
            p10: $this->percentile($months, 10),
            p50: $this->percentile($months, 50),
            p90: $this->percentile($months, 90),
            mean: round(array_sum($months) / $iterations, 2),
            min: round($months[0], 2),
            max: round($months[$iterations - 1], 2),
            iterations: $iterations,
        );
    }

    /**
     * @return array{days: list<array{day:int,rate:float,km:int,earning:float}>, total: float}
     */
    public function sampleMonth(
        array $rate,
        array $km,
        int $days = 25,
    ): array {
        $this->validate($rate, 'rate');
        $this->validate($km, 'km');

        $rows = [];
        $total = 0.0;

        for ($d = 1; $d <= $days; $d++) {
            $r = round($this->triangular($rate), 1);
            $k = (int) round($this->triangular($km));
            $earning = round($r * $k, 2);
            $total += $earning;
            $rows[] = ['day' => $d, 'rate' => $r, 'km' => $k, 'earning' => $earning];
        }

        return ['days' => $rows, 'total' => round($total, 2)];
    }

    private function simulateMonth(array $rate, array $km, int $days): float
    {
        $total = 0.0;
        for ($d = 0; $d < $days; $d++) {
            $total += $this->triangular($rate) * $this->triangular($km);
        }
        return $total;
    }

    private function triangular(array $p): float
    {
        ['min' => $a, 'mode' => $c, 'max' => $b] = $p;

        if ($a === $b) {
            return (float) $a;
        }

        $u = $this->rng->getInt(0, self::PRECISION) / self::PRECISION;
        $f = ($c - $a) / ($b - $a);

        return $u < $f
            ? $a + sqrt($u * ($b - $a) * ($c - $a))
            : $b - sqrt((1 - $u) * ($b - $a) * ($b - $c));
    }

    private function percentile(array $sorted, int $p): float
    {
        return round($sorted[(int) floor(($p / 100) * (count($sorted) - 1))], 2);
    }

    private function validate(array $p, string $name): void
    {
        foreach (['min', 'mode', 'max'] as $key) {
            if (!isset($p[$key]) || !is_numeric($p[$key]) || $p[$key] < 0) {
                throw new InvalidArgumentException("{$name}.{$key} must be a non-negative number.");
            }
        }
        if (!($p['min'] <= $p['mode'] && $p['mode'] <= $p['max'])) {
            throw new InvalidArgumentException("{$name} must satisfy min <= mode <= max.");
        }
    }
}