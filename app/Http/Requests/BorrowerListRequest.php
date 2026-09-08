<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class BorrowerListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth is handled by the route middleware
    }

    public function rules(): array
    {
        return [
            // Pagination
            'per_page'       => ['sometimes', 'integer', 'min:1', 'max:100'],

            // Sorting
            'sort_by'        => ['sometimes', 'string', 'in:id,created_at,loan_amount,status,updated_at'],
            'sort_order'     => ['sometimes', 'string', 'in:asc,desc'],

            // Filters
            'dealer_id'      => ['sometimes', 'string', 'exists:dealers,dealer_unq'],
            'status'         => ['sometimes'],  // validated below as string or array
            'status.*'       => ['string', 'in:NEW,APPROVED,REJECTED,HOLD,AWAITED,INPROCESS,DISBURSED,CLOSED,INTRANSIT,SOFT_APPROVED,CREDITCHECK,FI,CAM_REPORT'],
            'search'         => ['sometimes', 'string', 'min:2', 'max:100'],
            'date_from'      => ['sometimes', 'date', 'date_format:Y-m-d'],
            'date_to'        => ['sometimes', 'date', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'executive_id'   => ['sometimes', 'integer', 'exists:admin_users,id'],
            'lender_id'      => ['sometimes', 'integer'],
            'loan_amount_min'=> ['sometimes', 'numeric', 'min:0'],
            'loan_amount_max'=> ['sometimes', 'numeric', 'min:0', 'gte:loan_amount_min'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.*.in'          => 'One or more loan statuses provided are invalid.',
            'date_to.after_or_equal' => 'The end date must be on or after the start date.',
            'loan_amount_max.gte'  => 'Maximum loan amount must be greater than or equal to the minimum.',
            'search.min'           => 'Search term must be at least 2 characters.',
        ];
    }

    /**
     * Normalise the status parameter so it's always an array internally,
     * regardless of whether the client sends a string or array.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('status') && is_string($this->status)) {
            $this->merge(['status' => explode(',', $this->status)]);
        }
    }

    /**
     * Return only the first validation error (matching the project convention).
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'status'  => 'failed',
                'message' => $validator->errors()->first(),
                'data'    => null,
            ], 422)
        );
    }
}
