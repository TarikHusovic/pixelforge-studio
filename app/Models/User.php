<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $is_admin
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_admin' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** @return HasMany<Generation, $this> */
    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class);
    }

    /** @return HasMany<CreditPurchase, $this> */
    public function creditPurchases(): HasMany
    {
        return $this->hasMany(CreditPurchase::class);
    }

    /** Credits bought minus what finished generations cost, in nanodollars. */
    public function balanceNanos(): int
    {
        return (int) $this->creditPurchases()->sum('amount_nanos') - (int) $this->generations()->sum('cost_nanos');
    }

    /** The most the user's in-flight generations can still cost, held back from the balance. */
    public function reservedNanos(): int
    {
        return (int) $this->generations()->whereNull('cost_nanos')->sum('max_cost_nanos');
    }
}
