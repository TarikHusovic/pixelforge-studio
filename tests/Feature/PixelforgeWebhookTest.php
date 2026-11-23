<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\WebhookEvent;

it('acknowledges a validly signed event', function () {
    $generation = Generation::factory()->queued()->create();

    deliverWebhook(pixelforgeEvent(pixelforgeGeneration($generation, 'processing')))->assertSuccessful();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Processing);
});

it('rejects an event that isn\'t signed with our secret, and changes nothing', function (Closure $signature) {
    $generation = Generation::factory()->queued()->create();
    $event = pixelforgeEvent(pixelforgeGeneration($generation, 'succeeded'));

    deliverWebhook($event, $signature(json_encode($event)))->assertClientError();

    expect($generation->refresh())->status->toBe(GenerationStatus::Queued)->cost_nanos->toBeNull()
        ->and(WebhookEvent::count())->toBe(0);
})->with([
    'no signature' => [fn (string $body) => ''],
    'wrong secret' => [fn (string $body) => pixelforgeSignature($body, secret: 'whsec_someone_else')],
    'different body' => [fn (string $body) => pixelforgeSignature($body.' ')],
    'signature only' => [fn (string $body) => 'v1='.hash_hmac('sha256', $body, 'whsec_test')],
    'older than 5 minutes' => [fn (string $body) => pixelforgeSignature($body, now()->subSeconds(301)->getTimestamp())],
    'more than 5 minutes ahead' => [fn (string $body) => pixelforgeSignature($body, now()->addSeconds(301)->getTimestamp())],
]);

it('rejects every event while no signing secret is configured', function () {
    config(['services.pixelforge.webhook_secret' => '']);
    $generation = Generation::factory()->queued()->create();
    $event = pixelforgeEvent(pixelforgeGeneration($generation, 'succeeded'));

    deliverWebhook($event, pixelforgeSignature(json_encode($event), secret: ''))->assertClientError();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});

it('records and charges an event delivered twice only once', function () {
    $generation = Generation::factory()->queued()->create();
    $event = pixelforgeEvent(pixelforgeGeneration($generation, 'succeeded'));

    deliverWebhook($event)->assertSuccessful();
    deliverWebhook($event)->assertSuccessful();

    expect(WebhookEvent::count())->toBe(1)
        ->and($generation->refresh()->cost_nanos)->toBe(381_887_500)
        ->and($generation->user->balanceNanos())->toBe(-381_887_500);
});

it('charges the event\'s own usage at its own rates, plus 25% rounded up', function () {
    $generation = Generation::factory()->queued()->create();
    $object = pixelforgeGeneration($generation, 'succeeded', [
        'usage' => ['input_text_tokens' => 13, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 1001, 'total_tokens' => 2114],
        'rates' => ['input_text_tokens' => '4.999', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
    ]);

    deliverWebhook(pixelforgeEvent($object))->assertSuccessful();

    // 13 x $4.999 + 1100 x $40 + 1001 x $12.50 per 1M = $0.056577487; x 1.25 = $0.07072185875, rounded up
    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->provider_cost_nanos->toBe(56_577_487)
        ->cost_nanos->toBe(70_721_859);
});

it('records the image of a succeeded generation', function () {
    $generation = Generation::factory()->queued()->create();
    $object = pixelforgeGeneration($generation, 'succeeded');

    deliverWebhook(pixelforgeEvent($object))->assertSuccessful();

    expect($generation->refresh())
        ->image_url->toBe($object['image_url'])
        ->image_expires_at->toISOString()->toStartWith('2026-11-05T14:03:29')
        ->completed_at->not->toBeNull();
});

it('makes a failed generation free', function () {
    $generation = Generation::factory()->processing()->create();

    deliverWebhook(pixelforgeEvent(pixelforgeGeneration($generation, 'failed')))->assertSuccessful();

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe('generation_failed')
        ->cost_nanos->toBe(0)
        ->provider_cost_nanos->toBe(0)
        ->and($generation->user->reservedNanos())->toBe(0);
});

it('keeps a succeeded generation succeeded at the same cost when a late processing event arrives', function () {
    $generation = Generation::factory()->queued()->create();

    deliverWebhook(pixelforgeEvent(pixelforgeGeneration($generation, 'succeeded')))->assertSuccessful();
    deliverWebhook(pixelforgeEvent(pixelforgeGeneration($generation, 'processing')))->assertSuccessful();

    expect($generation->refresh())->status->toBe(GenerationStatus::Succeeded)->cost_nanos->toBe(381_887_500);
});

it('never changes a finished generation', function (string $first, string $then) {
    $generation = Generation::factory()->queued()->create();
    deliverWebhook(pixelforgeEvent(pixelforgeGeneration($generation, $first)))->assertSuccessful();
    $finished = $generation->refresh()->only(['status', 'cost_nanos', 'provider_cost_nanos', 'error_code', 'image_url']);

    deliverWebhook(pixelforgeEvent(pixelforgeGeneration($generation, $then)))->assertSuccessful();

    expect($generation->refresh()->only(array_keys($finished)))->toBe($finished);
})->with([
    'failed after succeeded' => ['succeeded', 'failed'],
    'succeeded after failed' => ['failed', 'succeeded'],
]);

it('doesn\'t charge again for a second event about the same success', function () {
    $generation = Generation::factory()->queued()->create();
    deliverWebhook(pixelforgeEvent(pixelforgeGeneration($generation, 'succeeded')))->assertSuccessful();

    $retried = pixelforgeGeneration($generation, 'succeeded', ['usage' => ['input_text_tokens' => 99, 'input_image_tokens' => 0, 'output_tokens' => 6450, 'reasoning_tokens' => 8000]]);
    deliverWebhook(pixelforgeEvent($retried))->assertSuccessful();

    expect($generation->refresh()->cost_nanos)->toBe(381_887_500)
        ->and($generation->user->balanceNanos())->toBe(-381_887_500);
});

it('applies a result that arrives before our create call returned, matched by its metadata', function () {
    $generation = Generation::factory()->create();

    deliverWebhook(pixelforgeEvent(pixelforgeGeneration($generation, 'succeeded', ['id' => 'gen_EARLY'])))->assertSuccessful();

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->provider_id->toBe('gen_EARLY')
        ->cost_nanos->toBe(381_887_500);
});

it('matches a generation by its Pixelforge id when metadata is missing', function () {
    $generation = Generation::factory()->processing()->create();

    deliverWebhook(pixelforgeEvent(pixelforgeGeneration($generation, 'succeeded', ['metadata' => []])))->assertSuccessful();

    expect($generation->refresh()->cost_nanos)->toBe(381_887_500);
});

it('acknowledges a ping without doing anything', function () {
    $ping = ['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'created_at' => '2026-11-04T14:03:29Z', 'data' => []];

    deliverWebhook($ping)->assertSuccessful();

    expect(WebhookEvent::count())->toBe(0);
});

it('acknowledges an event about a generation that isn\'t ours without doing anything', function () {
    $someoneElses = Generation::factory()->make(['id' => '01JC2Z8Q4N6V0M3H7X5T9R2B1K', 'provider_id' => 'gen_NOT_OURS']);
    $ours = Generation::factory()->queued()->create();

    deliverWebhook(pixelforgeEvent(pixelforgeGeneration($someoneElses, 'succeeded')))->assertSuccessful();

    expect(WebhookEvent::count())->toBe(0)
        ->and($ours->refresh()->status)->toBe(GenerationStatus::Queued);
});

it('applies an event on redelivery when processing it failed the first time', function () {
    $generation = Generation::factory()->queued()->create();
    $event = pixelforgeEvent(pixelforgeGeneration($generation, 'succeeded'));
    $databaseHiccup = true;
    Generation::saving(function () use (&$databaseHiccup) {
        if ($databaseHiccup) {
            $databaseHiccup = false;

            throw new RuntimeException('Database went away.');
        }
    });

    deliverWebhook($event)->assertServerError();
    deliverWebhook($event)->assertSuccessful();

    expect(WebhookEvent::count())->toBe(1)
        ->and($generation->refresh())->status->toBe(GenerationStatus::Succeeded)->cost_nanos->toBe(381_887_500);
});

it('lets Pixelforge correct a generation we gave up submitting, when it had it after all', function () {
    $generation = Generation::factory()->create();
    $generation->markFailed('connection_failed', 'Could not reach Pixelforge.');

    deliverWebhook(pixelforgeEvent(pixelforgeGeneration($generation, 'succeeded')))->assertSuccessful();

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->provider_id->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
        ->error_code->toBeNull()
        ->cost_nanos->toBe(381_887_500)
        ->provider_cost_nanos->toBe(305_510_000);
});

it('holds credits again when Pixelforge turns out to be running a generation we gave up on', function () {
    $generation = Generation::factory()->create();
    $generation->markFailed('connection_failed', 'Could not reach Pixelforge.');

    deliverWebhook(pixelforgeEvent(pixelforgeGeneration($generation, 'processing')))->assertSuccessful();

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Processing)
        ->cost_nanos->toBeNull()
        ->completed_at->toBeNull()
        ->and($generation->user->reservedNanos())->toBe($generation->max_cost_nanos);
});
