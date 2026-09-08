<?php
declare(strict_types=1);

namespace App\Services\Product;

use App\Models\OemDynamicProduct;

final class RuleViewTransformer
{
    private const FIELD_LABELS = [
        'credit_score' => 'Credit Score', 'is_ntc' => 'New to Credit',
        'residence_type' => 'Residence', 'pincode' => 'Pincode', 'state' => 'State',
        'gender' => 'Gender', 'age' => 'Age', 'dealer_borrower_km' => 'Dealer–Borrower KM',
        'years_at_address' => 'Years at Address', 'years_in_city' => 'Years in City',
        'has_co_applicant' => 'Has Co-Applicant', 'has_guarantor' => 'Has Guarantor',
        'guarantor_owns_house' => 'Guarantor Owns House',
    ];

    private const OP_LABELS = [
        '='       => 'is equal to',
        '>='      => 'is greater than or equal to',
        '<='      => 'is less than or equal to',
        'between' => 'is between',
        'in'      => 'is one of',
    ];

    public function forProduct(OemDynamicProduct $product): array
    {
        $set = $product->defaultRuleSet();

        return [
            'product' => [
                'id'                => $product->id,
                'code'              => $product->oem_product_unq,
                'name'              => $product->product_name,
                'oem'               => $product->oem?->manufacturer,
                'type'              => $product->product_type,
                'product_sub_type'  => $product->product_sub_type,
                'battery'           => $product->battery_type,
                'ex_showroom_price' => (float) $product->ex_showroom_price,
                'interest_type'     => $product->interest_type,
                'status'            => $product->status,
            ],
            'version' => $set?->version,
            'rules'   => $set ? $this->groupRules($set) : $this->emptyGroups(),
        ];
    }

    private function groupRules($set): array
    {
        $g = $this->emptyGroups();

        foreach ($set->rules as $rule) {
            $when = $rule->conditions->map(fn ($c) => $this->readableCond($c))->all();
            $line = ['when' => $when, 'when_text' => $this->joinWhen($when)];

            switch ($rule->rule_type) {
                case 'funding':
                    $g['loan_amount'][] = $line + [
                        'min' => $rule->output['min'] ?? 0,
                        'max' => $rule->output['amount'] ?? 0,
                    ];
                    break;
                case 'roi':
                    $g['interest'][] = $line + [
                        'rate' => $rule->output['rate'] ?? 0,
                        'type' => $rule->output['interest_type'] ?? '',
                    ];
                    break;
                case 'tenure':
                    $g['tenure'][] = $line + [
                        'min' => $rule->output['min'] ?? 0,
                        'max' => $rule->output['max'] ?? 0,
                    ];
                    break;
                case 'processing_fee':
                    $g['processing_fee'][] = $line + ['label' => $this->feeLabel($rule->output)];
                    break;
                case 'component':
                    $g['charges'][] = $line + [
                        'key'    => $rule->output['key'] ?? '',
                        'amount' => $rule->output['amount'] ?? 0,
                    ];
                    break;
                case 'constraint':
                    $g['limits'][] = $line + ['label' => $this->limitLabel($rule->output, $when)];
                    break;
            }
        }
        return $g;
    }

    private function emptyGroups(): array
    {
        return ['loan_amount'=>[], 'interest'=>[], 'tenure'=>[], 'processing_fee'=>[], 'charges'=>[], 'limits'=>[]];
    }

    private function readableCond($c): array
    {
        $field = self::FIELD_LABELS[$c->attribute] ?? $c->attribute;
        $op    = self::OP_LABELS[$c->operator] ?? $c->operator;
        $val   = is_array($c->value) ? implode(', ', $c->value) : $c->value;

        return [
            'field' => $field, 'operator' => $op, 'value' => $val,
            'text'  => trim("$field $op $val"),
        ];
    }

    private function joinWhen(array $when): string
    {
        return $when ? implode(' and ', array_column($when, 'text')) : 'Always';
    }

    private function feeLabel(array $o): string
    {
        if (($o['mode'] ?? '') === 'percent') {
            return "{$o['rate']}% + GST";
        }
        $gst = ($o['gst'] ?? '') === 'inclusive' ? '(incl GST)' : '+ GST';
        return "₹" . number_format((float) ($o['amount'] ?? 0)) . " flat $gst";
    }

    private function limitLabel(array $o, array $when): string
    {
        return match ($o['gate'] ?? '') {
            'distance'       => "Max distance {$o['max_km']} km",
            'rented_pincode' => 'Rented allowed only in listed pincodes',
            'require'        => $o['message'] ?? 'Must satisfy conditions',
            'require_any'    => ($o['message'] ?? 'Must have any of') . ' (' . implode(' / ', $o['facts'] ?? []) . ')',
            default          => 'Limit',
        };
    }
}