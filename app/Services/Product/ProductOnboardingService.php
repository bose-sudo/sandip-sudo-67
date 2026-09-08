<?php

declare(strict_types=1);

namespace App\Services\Product;

use App\Support\LegacyProductType;
use App\Models\{OemDynamicProduct, ProductRuleSet, ProductRule, ProductRuleCondition};
use Illuminate\Support\Facades\DB;

final class ProductOnboardingService
{
    /** Baseline financial fields; accepts `interest` or `interest_rate` for interest. */
    private function baselineFields(array $data, ?OemDynamicProduct $existing = null): array
    {
        $interest = $data['interest'] ?? $data['interest_rate'] ?? $existing?->interest ?? 0;

        return [
            'product_sub_type'       => $data['product_sub_type']       ?? $existing?->product_sub_type       ?? null,
            'product_sub_type_id'    => $data['product_sub_type_id']    ?? $existing?->product_sub_type_id    ?? null,
            'battery_type'           => $data['battery_type']           ?? $existing?->battery_type           ?? null,
            'loan_to_value_ratio'    => $data['loan_to_value_ratio']    ?? $existing?->loan_to_value_ratio    ?? null,
            'max_dealer_borrower_km' => $data['max_dealer_borrower_km'] ?? $existing?->max_dealer_borrower_km ?? null,
            'min_loan_amount'        => $data['min_loan_amount']        ?? $existing?->min_loan_amount        ?? 0,
            'max_loan_amount'        => $data['max_loan_amount']        ?? $existing?->max_loan_amount        ?? 0,
            'min_loan_tenure'        => $data['min_loan_tenure']        ?? $existing?->min_loan_tenure        ?? 0,
            'max_loan_tenure'        => $data['max_loan_tenure']        ?? $existing?->max_loan_tenure        ?? 0,
            'interest_type'          => $data['interest_type']          ?? $existing?->interest_type          ?? OemDynamicProduct::REDUCING,
            'interest'               => $interest,
            'processing_fee'         => $data['processing_fee']         ?? $existing?->processing_fee         ?? 0,
            'subvention_amount'      => $data['subvention_amount']      ?? $existing?->subvention_amount      ?? 0,
            'fldg'                   => $data['fldg']                   ?? $existing?->fldg                   ?? 0,
            'iot'                    => $data['iot']                    ?? $existing?->iot                    ?? 0,
            'insurance_1st_year'     => $data['insurance_1st_year']     ?? $existing?->insurance_1st_year     ?? 0,
            'insurance_2nd_year'     => $data['insurance_2nd_year']     ?? $existing?->insurance_2nd_year     ?? 0,
        ];
    }
    
    /** Create product + empty draft default rule set. */
    public function createProduct(array $data, ?int $userId, ?string $ip): OemDynamicProduct
    {
        return DB::transaction(function () use ($data, $userId, $ip) {
            $product = OemDynamicProduct::create(array_merge([
                'oem_id'            => $data['oem_id'],
                'product_name'      => $data['product_name'],
                'product_type'      => LegacyProductType::name($data['product_type']) ?? $data['product_type'],
                'ex_showroom_price' => $data['ex_showroom_price'],
                'pricing_source'    => 'oem_dynamic',
                'status'            => 'active',
                'modified_by'       => $userId,
                'ip'                => $ip,
            ], $this->baselineFields($data)));

            $product->update(['oem_product_unq' => 'FIN-DYP-' . $product->id]);

            // empty default set, draft — rules authored separately
            // ProductRuleSet::create([
            //     'product_id' => $product->id,
            //     'dealer_id'  => null,
            //     'scope'      => 'product_default',
            //     'version'    => 1,
            //     'status'     => 'draft',
            // ]);

            return $product->fresh();
        });
    }

    // Extract the insert loop into a shared private method (used by create + republish)
    private function writeRules(ProductRuleSet $set, array $rules): void
    {
        foreach ($rules as $r) {
            $rule = ProductRule::create([
                'rule_set_id' => $set->id,
                'rule_type'   => $r['rule_type'],
                'priority'    => $r['priority'] ?? count($r['conditions'] ?? []) * 10,
                'output'      => $r['output'],
                'status'      => 'active',
            ]);
            foreach ($r['conditions'] ?? [] as $c) {
                ProductRuleCondition::create([
                    'rule_id'   => $rule->id,
                    'attribute' => $c['attribute'],
                    'operator'  => $c['operator'],
                    'value'     => $c['value'],
                ]);
            }
        }
    }

    public function updateProduct(OemDynamicProduct $product, array $data, ?int $userId, ?string $ip): OemDynamicProduct
    {
        $product->update(array_merge([
            'product_name'      => $data['product_name']      ?? $product->product_name,
            'product_type'      => isset($data['product_type'])
                ? (LegacyProductType::name($data['product_type']) ?? $data['product_type'])
                : $product->product_type,
            'ex_showroom_price' => $data['ex_showroom_price'] ?? $product->ex_showroom_price,
            'modified_by'       => $userId,
            'ip'                => $ip,
        ], $this->baselineFields($data, $product)));
        return $product->fresh();
    }

    /** Re-author rules as a NEW version. Old versions archived + still referenced by past quotes. */
    public function republishRules(OemDynamicProduct $product, array $rules, ?int $dealerId = null): ProductRuleSet
    {
        return DB::transaction(function () use ($product, $rules, $dealerId) {
            $scope = $dealerId ? 'dealer_override' : 'product_default';

            $scopeFilter = fn($q) => $dealerId
                ? $q->where('dealer_id', $dealerId)
                : $q->whereNull('dealer_id');

            $nextVersion = (ProductRuleSet::where('product_id', $product->id)
                ->where('scope', $scope)->where($scopeFilter)
                ->max('version') ?? 0) + 1;

            ProductRuleSet::where('product_id', $product->id)
                ->where('scope', $scope)->where($scopeFilter)
                ->where('status', 'active')
                ->update(['status' => 'archived', 'effective_to' => now()]);

            $set = ProductRuleSet::create([
                'product_id'     => $product->id,
                'dealer_id'      => $dealerId,
                'scope'          => $scope,
                'version'        => $nextVersion,
                'status'         => 'active',
                'effective_from' => now(),
            ]);

            $this->writeRules($set, $rules);
            return $set->fresh('rules.conditions');
        });
    }

    public function createWithRules(array $data, array $rules, ?int $userId, ?string $ip): OemDynamicProduct
    {
        return DB::transaction(function () use ($data, $rules, $userId, $ip) {
            $product = $this->createProduct($data, $userId, $ip);   // existing method
            if (!empty($rules)) {
                $this->republishRules($product, $rules);            // existing method
            }
            return $product->fresh('ruleSets.rules.conditions');
        });
    }
}
