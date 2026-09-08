<?php

declare(strict_types=1);

namespace App\Domain\Lending\Bre\Engine;

use App\Domain\Lending\Bre\Dto\CompiledRuleSet;

final class RuleEvaluator
{
    public function __construct(private readonly ConditionMatcher $matcher) {}

    /**
     * Single-match for funding/roi/processing_fee/tenure.
     * @return array{output:array,rule_id:int}|null
     */
    public function first(CompiledRuleSet $set, string $type, array $facts): ?array
    {
        foreach ($set->rules($type) as $rule) {
            if ($this->matcher->allMatch($rule->conditions, $facts)) {
                return ['output' => $rule->output, 'rule_id' => $rule->id];
            }
        }
        return null;
    }

    /** Multi-match keyed by output.key (components). */
    public function collectComponents(CompiledRuleSet $set, array $facts): array
    {
        $out = [];
        foreach ($set->rules('component') as $rule) {
            if ($this->matcher->allMatch($rule->conditions, $facts)) {
                $key = $rule->output['key'];
                if (!isset($out[$key])) {   // first (most specific) wins per key
                    $out[$key] = ['output' => $rule->output, 'rule_id' => $rule->id];
                }
            }
        }
        return $out;
    }

    /** All constraint rules (gates evaluated by resolver). */
    public function constraints(CompiledRuleSet $set): array
    {
        return $set->rules('constraint');
    }
}
