<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentHeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'code'             => $this->code,
            'description'      => $this->description,
            'calculation_type' => $this->calculation_type,
            'default_value'    => (float) $this->default_value,
            'percentage_basis' => $this->percentage_basis,
            'is_per_annum'     => $this->is_per_annum,
            'gst' => [
                'applicable' => $this->is_gst_applicable,
                'rate'       => (float) $this->gst_rate,
            ],
            'row_status' => $this->row_status,
            'is_system'  => $this->is_system,

            // Only included when groups are eager-loaded (show endpoint)
            'groups' => $this->whenLoaded('groups', fn () =>
                $this->groups->map(fn ($g) => [
                    'id'         => $g->id,
                    'name'       => $g->name,
                    'code'       => $g->code,
                    'row_status' => $g->row_status,
                ])
            ),

            'audit' => [
                'created_by'    => $this->created_by,
                'created_by_ip' => $this->created_by_ip,
                'updated_by'    => $this->updated_by,
                'updated_by_ip' => $this->updated_by_ip,
            ],
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}