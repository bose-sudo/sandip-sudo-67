<?php

declare(strict_types=1);

namespace App\Domain\Lending\Bre\Engine;

use App\Domain\Lending\Bre\Dto\CompiledRuleSet;
use App\Models\{OemDynamicProduct, ProductRuleSet};

final class RuleSetCompiler
{
    /** Load default set, layer dealer override per rule_type. */
    public function compile(OemDynamicProduct $product, ?int $dealerId): CompiledRuleSet
    {
        $default = $this->activeSet($product->id, null);
        $override = $dealerId ? $this->activeSet($product->id, $dealerId) : null;

        $byType = $default ? $this->group($default) : [];

        if ($override) {
            foreach ($this->group($override) as $type => $rules) {
                $byType[$type] = $rules;   // dealer wins for that whole rule_type
            }
        }

        return new CompiledRuleSet($byType, $default?->id, $override?->id);
    }

    private function activeSet(int $productId, ?int $dealerId): ?ProductRuleSet
    {
        return ProductRuleSet::with('rules.conditions')
            ->where('product_id', $productId)
            ->where('scope', $dealerId ? 'dealer_override' : 'product_default')
            ->when($dealerId, fn($q) => $q->where('dealer_id', $dealerId), fn($q) => $q->whereNull('dealer_id'))
            ->where('status', 'active')
            ->first();
    }

    /** rule_type => rules sorted priority DESC, then condition-count DESC (most specific first). */
    private function group(ProductRuleSet $set): array
    {
        $out = [];
        foreach ($set->rules as $rule) {
            $out[$rule->rule_type][] = $rule;
        }
        foreach ($out as &$rules) {
            usort($rules, fn($a, $b) =>
            $b->priority <=> $a->priority
                ?: $b->conditions->count() <=> $a->conditions->count()
                ?: $a->id <=> $b->id);
        }
        return $out;
    }
}
