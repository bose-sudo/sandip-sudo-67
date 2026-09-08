<?php

namespace App\Http\Requests\Product;

use App\Http\Requests\Concerns\FailsWithFirstError;
use Illuminate\Foundation\Http\FormRequest;

class AssignDealerRequest extends FormRequest
{
    use FailsWithFirstError;
    public function authorize(): bool
    {
        return true;
    }
    public function rules(): array
    {
        return [
            'dealer_ids'   => 'required|array|min:1',
            'dealer_ids.*' => 'integer|exists:dealers,id',
        ];
    }
}
