<?php

declare(strict_types=1);

namespace App\Domain\Lending\Bre\Engine;

use Illuminate\Support\Collection;

final class ConditionMatcher
{
    /** @param Collection $conditions ProductRuleCondition[] */
    public function allMatch(Collection $conditions, array $facts): bool
    {
        $hasScore = (int) ($facts['credit_score'] ?? 0) > 0;

        foreach ($conditions as $c) {
            if (!array_key_exists($c->attribute, $facts)) return false;

            // NTC is defined by absence of score. If a real score exists, an is_ntc
            // condition is not applicable — skip it rather than fail the rule.
            if ($c->attribute === 'is_ntc' && $hasScore) {
                continue;
            }

            if (!$this->one($c->operator, $facts[$c->attribute], $c->value)) return false;
        }
        return true;
    }

    private function one(string $op, mixed $fact, mixed $val): bool
    {
        return match ($op) {
            '='       => $this->eq($fact, $val),
            // 'in'      => in_array((string) $fact, array_map('strval', (array) $val), true),
            'in'      => $this->inList($fact, (array) $val),
            'between' => $fact >= $val[0] && $fact <= $val[1],
            '>='      => $fact >= $val,
            '<='      => $fact <= $val,
            default   => false,
        };
    }

    // private function eq(mixed $fact, mixed $val): bool
    // {
    //     // is_ntc etc. may arrive as 'true'/'false' strings — normalize booleans
    //     if (is_bool($fact)) $val = filter_var($val, FILTER_VALIDATE_BOOLEAN);
    //     return is_numeric($fact) && is_numeric($val) ? $fact == $val : (string) $fact === (string) $val;
    // }

    private function eq(mixed $fact, mixed $val): bool
    {
        if (is_bool($fact)) $val = filter_var($val, FILTER_VALIDATE_BOOLEAN);
        if (is_numeric($fact) && is_numeric($val)) return $fact == $val;
        return strtolower(trim((string) $fact)) === strtolower(trim((string) $val));
    }

    private function inList(mixed $fact, array $vals): bool
    {
        $f = strtolower(trim((string) $fact));
        foreach ($vals as $v) {
            if (strtolower(trim((string) $v)) === $f) return true;
        }
        return false;
    }
}
