<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A Pixelforge webhook event we've processed. The unique event_id is what stops a
 * redelivered event from being applied twice.
 *
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property string|null $generation_id
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $created_at
 */
class WebhookEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
