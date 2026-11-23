<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Pricing;
use App\Enums\GenerationStatus;
use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * An image a user asked for, generated on Pixelforge.
 *
 * Money columns are integer nanodollars. cost_nanos is what the user is charged: it stays
 * null while the generation is in flight, which keeps max_cost_nanos reserved against the
 * user's balance, and is set exactly once, when the generation reaches a final status.
 *
 * @property string $id ULID, also our Idempotency-Key and metadata.generation_id at Pixelforge
 * @property int $user_id
 * @property string|null $provider_id
 * @property GenerationStatus $status
 * @property string $prompt
 * @property string $size
 * @property string $quality
 * @property list<string> $reference_images
 * @property int $estimated_cost_nanos
 * @property int $max_cost_nanos
 * @property int|null $cost_nanos
 * @property int|null $provider_cost_nanos
 * @property array<string, string>|null $rates
 * @property array<string, int>|null $usage
 * @property string|null $image_url
 * @property CarbonImmutable|null $image_expires_at
 * @property string|null $error_code
 * @property string|null $error_message
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['prompt', 'size', 'quality', 'reference_images', 'estimated_cost_nanos', 'max_cost_nanos'])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUlids;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'reference_images' => '[]',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'estimated_cost_nanos' => 'integer',
            'max_cost_nanos' => 'integer',
            'cost_nanos' => 'integer',
            'provider_cost_nanos' => 'integer',
            'rates' => 'array',
            'usage' => 'array',
            'image_expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The generation a Pixelforge generation object belongs to.
     *
     * @param  array<string, mixed>  $object
     */
    public static function findForPixelforge(array $object): ?self
    {
        $id = $object['metadata']['generation_id'] ?? null;

        return (is_string($id) ? static::find($id) : null)
            ?? static::query()->where('provider_id', $object['id'])->first();
    }

    /**
     * Apply a Pixelforge generation object, from the create response, a webhook or a poll.
     *
     * @param  array<string, mixed>  $object
     */
    public function applyUpdate(array $object): void
    {
        $status = GenerationStatus::from($object['status']);

        $changes = [
            'provider_id' => $object['id'],
            'rates' => $object['rates'],
            'error_code' => $object['error']['code'] ?? null,
            'error_message' => $object['error']['message'] ?? null,
            'completed_at' => $object['completed_at'],
            'provider_cost_nanos' => null,
            'cost_nanos' => null,
        ];

        if ($status === GenerationStatus::Succeeded) {
            $usage = $object['usage'] ?? throw new UnexpectedValueException("Pixelforge generation {$object['id']} succeeded without usage.");
            $providerCost = Pricing::cost($usage, Pricing::parseRates($object['rates']));

            $changes = [
                ...$changes,
                'usage' => $usage,
                'provider_cost_nanos' => $providerCost,
                'cost_nanos' => Pricing::withMarkup($providerCost),
                'image_url' => $object['image_url'],
                'image_expires_at' => $object['image_url_expires_at'],
            ];
        }

        if ($status === GenerationStatus::Failed) {
            $changes = [...$changes, 'provider_cost_nanos' => 0, 'cost_nanos' => 0];
        }

        $this->advanceTo($status, $changes, fn (self $current): bool => $current->status->canBecome($status)
            // We gave up submitting it before Pixelforge confirmed it, yet Pixelforge has it after
            // all (say our last attempt timed out): its outcome wins, or its cost would go unrecorded.
            || ($current->status === GenerationStatus::Failed && $current->provider_id === null));
    }

    /**
     * Pixelforge refused the generation, or we gave up submitting it. Failed generations are free.
     * Only while it's still pending: once Pixelforge has accepted it (an early webhook can tell us
     * before our own request does), only Pixelforge decides how it ends and what it costs.
     */
    public function markFailed(string $code, string $message): void
    {
        $this->advanceTo(GenerationStatus::Failed, [
            'error_code' => $code,
            'error_message' => $message,
            'provider_cost_nanos' => 0,
            'cost_nanos' => 0,
            'completed_at' => now(),
        ], fn (self $current): bool => $current->status === GenerationStatus::Pending);
    }

    /**
     * The submit job, webhooks and polling can race on one generation, so whether the change
     * is allowed is decided on the locked row. Statuses only moving forward is what makes
     * duplicate and out-of-order updates harmless, and records the charge exactly once.
     *
     * @param  array<string, mixed>  $changes
     * @param  Closure(self): bool  $allowed
     */
    private function advanceTo(GenerationStatus $status, array $changes, Closure $allowed): void
    {
        DB::transaction(function () use ($status, $changes, $allowed) {
            $current = static::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();

            if ($allowed($current)) {
                $current->forceFill(['status' => $status, ...$changes])->save();
            }
        });

        $this->refresh();
    }
}
