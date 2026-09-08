<?php
// app/Http/Requests/DebitRequestListRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DebitRequestListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'borrower_id'    => 'nullable|integer|exists:loan_basic_details,id',
            'date'           => 'nullable|date_format:Y-m-d',
            'month'           => 'nullable|date_format:F',
            'date_from'      => 'nullable|date_format:Y-m-d',
            'start_date'      => 'nullable|date_format:Y-m-d',
            'date_to'        => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
            'end_date'        => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'status'         => 'nullable|string|in:initiated,success,failed,pending,submitted',
            'transaction_id' => 'nullable|string|max:100',
            'per_page'       => 'nullable|integer|min:1|max:200',
            'page'           => 'nullable|integer|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'status.in'              => 'status must be one of: initiated, success, failed, pending, submitted.',
            'date_to.after_or_equal' => 'date_to must be on or after date_from.',
        ];
    }
}