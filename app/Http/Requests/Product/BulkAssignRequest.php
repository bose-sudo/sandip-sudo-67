<?php
namespace App\Http\Requests\Product;
use App\Http\Requests\Concerns\FailsWithFirstError;
use Illuminate\Foundation\Http\FormRequest;

class BulkAssignRequest extends FormRequest
{
    use FailsWithFirstError;
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'product_ids'   => 'required|array|min:1',
            'product_ids.*' => 'integer|exists:oem_dynamic_products,id',
            'dealer_ids'    => 'required|array|min:1',
            'dealer_ids.*'  => 'integer|exists:dealers,id',
        ];
    }
}