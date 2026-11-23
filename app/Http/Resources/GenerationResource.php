<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a user sees of their generation. Provider cost, rates and usage stay server-side.
 *
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'in_flight' => ! $this->status->isFinal(),
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            'estimated_cost_nanos' => $this->estimated_cost_nanos,
            'max_cost_nanos' => $this->max_cost_nanos,
            'cost_nanos' => $this->cost_nanos,
            'image_url' => $this->image_url,
            'image_expires_at' => $this->image_expires_at,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at,
        ];
    }
}
