<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentHeadGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'code'        => $this->code,
            'description' => $this->description,
            'row_status'  => $this->row_status,

            // Loaded only when withHeads is eager-loaded (index/show)
            'heads' => $this->whenLoaded('heads', function () {
                return $this->heads->map(fn ($head) => [
                    'id'               => $head->id,
                    'name'             => $head->name,
                    'code'             => $head->code,
                    'calculation_type' => $head->calculation_type,
                    'default_value'    => (float) $head->default_value,
                    'percentage_basis' => $head->percentage_basis,
                    'is_per_annum'     => $head->is_per_annum,
                    'gst' => [
                        'applicable' => $head->is_gst_applicable,
                        'rate'       => (float) $head->gst_rate,
                    ],
                    'row_status'       => $head->row_status,
                ]);
            }),

            'audit' => [
                'created_by'    => $this->created_by,
                'created_by_ip' => $this->created_by_ip,
                'updated_by'    => $this->updated_by,
                'updated_by_ip' => $this->updated_by_ip,
            ],
            'created_at'  => $this->created_at?->toDateTimeString(),
            'updated_at'  => $this->updated_at?->toDateTimeString(),
        ];
    }
}