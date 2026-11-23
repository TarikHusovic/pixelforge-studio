<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Credits a user bought (a fake purchase: no payment is taken).
 *
 * @property int $id
 * @property int $user_id
 * @property int $amount_nanos
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['amount_nanos'])]
class CreditPurchase extends Model
{
    /** What the "Buy credits" button offers, in whole dollars. */
    public const array DOLLAR_AMOUNTS = [5, 10, 25];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_nanos' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
