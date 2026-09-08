<?php
namespace App\Http\Requests\Product;

use App\Http\Requests\Concerns\FailsWithFirstError;
use Illuminate\Foundation\Http\FormRequest;

class AuthorRulesRequest extends FormRequest
{
    use FailsWithFirstError;
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'rules'                        => 'required|array|min:1',
            'rules.*.rule_type'            => 'required|in:funding,roi,processing_fee,tenure,component,constraint',
            'rules.*.priority'             => 'nullable|integer',
            'rules.*.output'               => 'required|array',
            'rules.*.conditions'           => 'nullable|array',
            'rules.*.conditions.*.attribute' => 'required_with:rules.*.conditions|string',
            'rules.*.conditions.*.operator'  => 'required_with:rules.*.conditions|in:=,in,between,>=,<=',
            'rules.*.conditions.*.value'     => 'required_with:rules.*.conditions',
        ];
    }
}