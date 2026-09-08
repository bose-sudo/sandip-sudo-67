<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Concerns\FailsWithFirstError;

class StoreDynamicProductRequest extends FormRequest
{
    use FailsWithFirstError;
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (!$this->has('interest') && $this->has('interest_rate')) {
            $this->merge(['interest' => $this->input('interest_rate')]);
        }

        if (!$this->has('iot') && $this->has('iot_amount')) {
            $this->merge(['iot' => $this->input('iot_amount')]);
        }
        if (!$this->has('fldg') && $this->has('fldg_amount')) {
            $this->merge(['fldg' => $this->input('fldg_amount')]);
        }
    }

    public function rules(): array
    {
        return [
            'oem_id' => 'sometimes|required|integer|exists:manufacturers,id',
            'product_name' => ['sometimes', 'required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9\s\-\(\)]+$/'],
            'product_type' => ['sometimes', 'required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9\s\-\(\)]+$/'],
            'ex_showroom_price' => 'sometimes|required|numeric|min:0',
            'product_sub_type'       => ['nullable', 'string', 'max:100'],
            'product_sub_type_id'    => ['nullable', 'integer'],
            'battery_type'           => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9\s]+$/'],
            'loan_to_value_ratio'    => 'nullable|numeric|min:0',
            'max_dealer_borrower_km' => 'nullable|numeric|min:0',
            'interest_type'          => 'nullable|in:reducing,flat',
            // 'interest'               => 'nullable|numeric|min:0',
            'interest_rate'          => 'nullable|numeric|min:0',
            'min_loan_amount'        => 'nullable|numeric|min:0',
            'max_loan_amount'        => 'nullable|numeric|min:0|gte:min_loan_amount',
            'min_loan_tenure'        => 'nullable|integer|min:0',
            'max_loan_tenure'        => 'nullable|integer|min:0|gte:min_loan_tenure',
            'processing_fee'         => 'nullable|numeric|min:0',
            'subvention_amount'      => 'nullable|numeric|min:0',
            'fldg'                   => 'nullable|numeric|min:0',
            'fldg_amount'            => 'nullable|numeric|min:0',
            'iot'                    => 'nullable|numeric|min:0',
            'iot_amount'             => 'nullable|numeric|min:0',
            'insurance_1st_year'     => 'nullable|numeric|min:0',
            'insurance_2nd_year'     => 'nullable|numeric|min:0',

            'rules'                          => 'sometimes|array',
            'rules.*.rule_type'              => 'required_with:rules|in:funding,roi,processing_fee,tenure,component,constraint',
            'rules.*.priority'              => 'nullable|integer',
            'rules.*.output'                 => 'required_with:rules|array',
            'rules.*.conditions'             => 'nullable|array',
            'rules.*.conditions.*.attribute' => 'required_with:rules.*.conditions|string',
            'rules.*.conditions.*.operator'  => 'required_with:rules.*.conditions|in:=,in,between,>=,<=',
            'rules.*.conditions.*.value'     => 'required_with:rules.*.conditions',
        ];
    }
}
