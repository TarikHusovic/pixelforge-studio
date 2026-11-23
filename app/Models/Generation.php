<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Pricing;
use App\Enums\GenerationStatus;
use Carbon\CarbonImmutable;
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
        ];

        if ($status === GenerationStatus::Succeeded) {
            $usage = $object['usage'] ?? throw new UnexpectedValueException("Pixelforge generation {$object['id']} succeeded without usage.");
            $providerCost = Pricing::cost($usage, Pricing::parseRates($object['rates']));

            $changes += [
                'usage' => $usage,
                'provider_cost_nanos' => $providerCost,
                'cost_nanos' => Pricing::withMarkup($providerCost),
                'image_url' => $object['image_url'],
                'image_expires_at' => $object['image_url_expires_at'],
                'completed_at' => $object['completed_at'],
            ];
        }

        if ($status === GenerationStatus::Failed) {
            $changes += ['provider_cost_nanos' => 0, 'cost_nanos' => 0, 'completed_at' => $object['completed_at']];
        }

        $this->advanceTo($status, $changes);
    }

    /** Pixelforge refused the generation, or we gave up submitting it. Failed generations are free. */
    public function markFailed(string $code, string $message): void
    {
        $this->advanceTo(GenerationStatus::Failed, [
            'error_code' => $code,
            'error_message' => $message,
            'provider_cost_nanos' => 0,
            'cost_nanos' => 0,
            'completed_at' => now(),
        ]);
    }

    /**
     * The submit job, webhooks and polling can race on one generation, so the status
     * check and the write happen under a row lock. Only ever moving forward is what makes
     * duplicate and out-of-order updates harmless, and records the charge exactly once.
     *
     * @param  array<string, mixed>  $changes
     */
    private function advanceTo(GenerationStatus $status, array $changes): void
    {
        DB::transaction(function () use ($status, $changes) {
            $locked = static::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->canBecome($status)) {
                $locked->forceFill(['status' => $status, ...$changes])->save();
            }
        });

        $this->refresh();
    }
}
