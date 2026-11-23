<?php

declare(strict_types=1);

use App\Billing\Money;
use App\Models\Generation;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        Http::preventStrayRequests();
        $this->withoutVite(); // pages render without a fresh asset build
    })
    ->in('Feature');

/*
| Builders for what Pixelforge sends us, following its docs (rates, the worked example
| generation and the pricing payload are copied from there).
*/

/** @return array<string, mixed> GET /v1/pricing */
function pixelforgePricing(): array
{
    return [
        'object' => 'pricing',
        'currency' => 'usd',
        'unit' => 'per_1m_tokens',
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ];
}

function fakePixelforgePricing(): void
{
    Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::response(pixelforgePricing())]);
}

/**
 * Pixelforge's generation object for $generation at $status. A succeeded one carries the
 * docs' example usage, which at the docs' rates costs $0.305510000 ($0.381887500 to the user).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pixelforgeGeneration(Generation $generation, string $status = 'queued', array $overrides = []): array
{
    $id = $generation->provider_id ?? 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K';

    return [
        'id' => $id,
        'object' => 'generation',
        'status' => $status,
        'prompt' => $generation->prompt,
        'size' => $generation->size,
        'quality' => $generation->quality,
        'reference_images' => $generation->reference_images,
        'metadata' => ['generation_id' => $generation->id],
        'image_url' => $status === 'succeeded' ? "https://pixelforge.promptedit.dev/images/{$id}.png?expires=1762351409&signature=abc" : null,
        'image_url_expires_at' => $status === 'succeeded' ? '2026-11-05T14:03:29Z' : null,
        'usage' => $status === 'succeeded'
            ? ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184, 'total_tokens' => 10411]
            : null,
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'error' => $status === 'failed' ? ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'] : null,
        'created_at' => '2026-11-04T14:03:11Z',
        'started_at' => in_array($status, ['processing', 'succeeded', 'failed'], true) ? '2026-11-04T14:03:13Z' : null,
        'completed_at' => in_array($status, ['succeeded', 'failed'], true) ? '2026-11-04T14:03:29Z' : null,
        ...$overrides,
    ];
}

/** A Pixelforge error response, e.g. pixelforgeError('overloaded', 503, retryAfter: 2). */
function pixelforgeError(string $code, int $status, ?int $retryAfter = null): PromiseInterface
{
    return Http::response(
        ['error' => ['type' => 'api_error', 'code' => $code, 'message' => "Pixelforge says {$code}."]],
        $status,
        $retryAfter === null ? [] : ['Retry-After' => (string) $retryAfter],
    );
}

/**
 * The webhook event Pixelforge sends when a generation reaches $object's status.
 *
 * @param  array<string, mixed>  $object
 * @return array<string, mixed>
 */
function pixelforgeEvent(array $object, ?string $eventId = null): array
{
    return [
        'id' => $eventId ?? 'evt_'.Str::ulid(),
        'object' => 'event',
        'type' => "generation.{$object['status']}",
        'created_at' => '2026-11-04T14:03:29Z',
        'data' => ['object' => $object],
    ];
}

/** "t=<unix time>,v1=<hex HMAC-SHA256 of '<t>.<body>'>", as Pixelforge signs a delivery. */
function pixelforgeSignature(string $body, ?int $timestamp = null, string $secret = 'whsec_test'): string
{
    $timestamp ??= now()->getTimestamp();

    return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
}

/**
 * Delivers $event to our webhook endpoint like Pixelforge does, validly signed unless
 * $signature says otherwise ('' sends no signature header).
 *
 * @param  array<string, mixed>  $event
 */
function deliverWebhook(array $event, ?string $signature = null): TestResponse
{
    $body = json_encode($event, JSON_THROW_ON_ERROR);
    $signature ??= pixelforgeSignature($body);

    return test()->call('POST', '/api/webhooks/pixelforge', server: array_filter([
        'CONTENT_TYPE' => 'application/json',
        'HTTP_USER_AGENT' => 'Pixelforge-Webhooks/1.0',
        'HTTP_PIXELFORGE_EVENT_ID' => $event['id'],
        'HTTP_PIXELFORGE_SIGNATURE' => $signature,
    ]), content: $body);
}

function userWithCredits(int $nanos = 10 * Money::NANOS_PER_DOLLAR): User
{
    $user = User::factory()->create();
    $user->creditPurchases()->create(['amount_nanos' => $nanos]);

    return $user;
}
