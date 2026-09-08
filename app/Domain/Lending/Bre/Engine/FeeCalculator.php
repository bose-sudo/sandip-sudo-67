<?php

declare(strict_types=1);

namespace App\Domain\Lending\Bre\Engine;

final class FeeCalculator
{
    /** @return array{base:float,gst:float,total:float} */
    public function compute(array $output, float $loanAmount): array
    {
        $gstRate = (float) ($output['gst_rate'] ?? 18);

        $raw = match ($output['mode']) {
            'flat'    => (float) $output['amount'],
            'percent' => round($loanAmount * ((float) $output['rate'] / 100), 2),
        };

        if (($output['gst'] ?? 'exclusive') === 'inclusive') {
            $base = round($raw / (1 + $gstRate / 100), 2);
            return ['base' => $base, 'gst' => round($raw - $base, 2), 'total' => $raw];
        }

        $gst = round($raw * $gstRate / 100, 2);
        return ['base' => $raw, 'gst' => $gst, 'total' => round($raw + $gst, 2)];
    }
}