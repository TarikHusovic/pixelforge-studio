<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use stdClass;

/**
 * A line on the user's statement, a row of CreditController's ledger query.
 *
 * @property stdClass $resource
 */
class CreditEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->resource->type,
            'id' => $this->resource->id,
            'status' => $this->resource->status,
            'prompt' => $this->resource->prompt,
            'amount_nanos' => (int) $this->resource->amount_nanos,
            'created_at' => CarbonImmutable::parse($this->resource->created_at),
        ];
    }
}
