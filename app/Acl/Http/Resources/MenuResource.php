<?php

namespace App\Acl\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MenuResource extends JsonResource
{
    /**
     * Transform already-built menu array node.
     * Since MenuBuilderService returns plain arrays, this wraps them for consistent API shape.
     */
    public function toArray(Request $request): array
    {
        // $this->resource is the array built by MenuBuilderService
        return [
            'id'         => $this->resource['id'],
            'name'       => $this->resource['name'],
            'slug'       => $this->resource['slug'],
            'icon'       => $this->resource['icon'],
            'route_name' => $this->resource['route_name'],
            'url'        => $this->resource['url'],
            'is_dropdown' => $this->resource['is_dropdown'],
            'children'   => collect($this->resource['children'] ?? [])
                ->map(fn ($child) => (new self($child))->toArray($request))
                ->values()
                ->all(),
        ];
    }
}
