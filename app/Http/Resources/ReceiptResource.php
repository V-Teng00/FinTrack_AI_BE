<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_name' => $this->store_name,
            'date' => $this->date->format('Y-m-d'),
            'total' => (float) $this->total,
            'total_myr' => (float) $this->total_myr,
            'currency' => $this->currency,
            'category' => $this->category,
            'thumbnail_url' => $this->image_path
                ? \Storage::disk('public')->url($this->image_path)
                : null,
            'items' => ReceiptItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
