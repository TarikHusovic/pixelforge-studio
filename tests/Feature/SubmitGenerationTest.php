<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const CREATE_URL = 'pixelforge.promptedit.dev/v1/generations';

/** Runs the job as the queue would on its $attempt-th attempt. */
function runSubmitGeneration(Generation $generation, int $attempt = 1): SubmitGeneration
{
    $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();
    $job->job->attempts = $attempt;
    $job->handle(app(PixelforgeClient::class));

    return $job;
}

it('records the generation as queued once Pixelforge accepts it', function () {
    $generation = Generation::factory()->create();
    Http::fake([CREATE_URL => Http::response(pixelforgeGeneration($generation, 'queued', ['id' => 'gen_ACCEPTED']), 202)]);

    runSubmitGeneration($generation)->assertNotReleased();

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Queued)
        ->provider_id->toBe('gen_ACCEPTED')
        ->cost_nanos->toBeNull();
});

it('sends our generation id as the Idempotency-Key and in metadata, with only the parameters Pixelforge accepts', function () {
    $generation = Generation::factory()->create([
        'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/style.png'],
    ]);
    Http::fake([CREATE_URL => Http::response(pixelforgeGeneration($generation), 202)]);

    runSubmitGeneration($generation);

    [$request] = Http::recorded()->sole();
    expect($request)
        ->method()->toBe('POST')
        ->url()->toBe('https://pixelforge.promptedit.dev/v1/generations')
        ->hasHeader('Authorization', 'Bearer pf_test')->toBeTrue()
        ->hasHeader('Idempotency-Key', $generation->id)->toBeTrue()
        ->data()->toEqual([
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style.png'],
            'metadata' => ['generation_id' => $generation->id],
        ]);
});

it('clears the reason it was waiting once Pixelforge accepts it', function () {
    $generation = Generation::factory()->create(['error_code' => 'overloaded', 'error_message' => 'Pixelforge is overloaded.']);
    Http::fake([CREATE_URL => Http::response(pixelforgeGeneration($generation), 202)]);

    runSubmitGeneration($generation);

    expect($generation->refresh())->error_code->toBeNull()->error_message->toBeNull();
});

it('waits and retries while Pixelforge is busy, keeping the generation pending with the reason', function (string $code, int $status, int $retryAfter) {
    $generation = Generation::factory()->create();
    Http::fake([CREATE_URL => pixelforgeError($code, $status, $retryAfter)]);

    runSubmitGeneration($generation)->assertReleased(delay: $retryAfter);

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Pending)
        ->error_code->toBe($code)
        ->cost_nanos->toBeNull();
})->with([
    'overloaded' => ['overloaded', 503, 2],
    'concurrency limit' => ['concurrency_limit_exceeded', 429, 5],
    'rate limit' => ['rate_limit_exceeded', 429, 30],
]);

it('retries when it can\'t tell whether Pixelforge got the request', function (Closure $response, string $code) {
    $generation = Generation::factory()->create();
    Http::fake([CREATE_URL => $response()]);

    runSubmitGeneration($generation)->assertReleased(delay: 2);

    expect($generation->refresh())->status->toBe(GenerationStatus::Pending)->error_code->toBe($code);
})->with([
    'connection failure' => [fn () => Http::failedConnection(), 'connection_failed'],
    'bad gateway' => [fn () => Http::response('Bad gateway', 502), 'http_502'],
    'accepted, but the answer isn\'t JSON' => [fn () => Http::response('<html>Accepted</html>', 202), 'unreadable_response'],
]);

it('backs off further on every attempt', function (int $attempt, int $delay) {
    $generation = Generation::factory()->create();
    Http::fake([CREATE_URL => pixelforgeError('overloaded', 503, retryAfter: 2)]);

    runSubmitGeneration($generation, $attempt)->assertReleased(delay: $delay);
})->with([[1, 2], [2, 4], [3, 8], [5, 32], [6, 60], [9, 60]]);

it('fails the generation for free, without retrying, when Pixelforge refuses it', function (string $code, int $status) {
    $generation = Generation::factory()->create();
    Http::fake([CREATE_URL => pixelforgeError($code, $status)]);

    runSubmitGeneration($generation)->assertNotReleased();

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe($code)
        ->cost_nanos->toBe(0)
        ->provider_cost_nanos->toBe(0);
})->with([
    'blocked prompt' => ['content_policy_violation', 400],
    'daily quota used up' => ['daily_limit_exceeded', 429],
    'invalid parameters' => ['validation_failed', 422],
    'idempotency key reused' => ['idempotency_key_reused', 409],
    'bad api key' => ['invalid_api_key', 401],
]);

it('gives up for free once a generation has waited 30 minutes', function () {
    $generation = Generation::factory()->create(['created_at' => now()->subMinutes(30)->addSecond()]);
    Http::fake([CREATE_URL => pixelforgeError('overloaded', 503, retryAfter: 2)]);

    runSubmitGeneration($generation)->assertNotReleased();

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe('overloaded')
        ->cost_nanos->toBe(0);
});

it('charges a replayed generation that already succeeded once, even when its webhook comes too', function () {
    $generation = Generation::factory()->create();
    $succeeded = pixelforgeGeneration($generation, 'succeeded');
    Http::fake([CREATE_URL => Http::response($succeeded, 202, ['Idempotent-Replayed' => 'true'])]);

    runSubmitGeneration($generation);
    deliverWebhook(pixelforgeEvent($succeeded))->assertSuccessful();

    expect($generation->refresh())->status->toBe(GenerationStatus::Succeeded)->cost_nanos->toBe(381_887_500)
        ->and($generation->user->balanceNanos())->toBe(-381_887_500);
});

it('doesn\'t resubmit a generation an early webhook shows Pixelforge already has', function () {
    $generation = Generation::factory()->create();
    $queuedJob = serialize(new SubmitGeneration($generation));
    deliverWebhook(pixelforgeEvent(pixelforgeGeneration($generation, 'processing')))->assertSuccessful();
    Http::fake();

    unserialize($queuedJob)->handle(app(PixelforgeClient::class));

    Http::assertNothingSent();
    expect($generation->refresh()->status)->toBe(GenerationStatus::Processing);
});

it('keeps a result that arrived by webhook while the create call was still in flight', function () {
    $generation = Generation::factory()->create();
    Http::fake([CREATE_URL => function (Request $request) use ($generation) {
        deliverWebhook(pixelforgeEvent(pixelforgeGeneration($generation, 'succeeded')))->assertSuccessful();

        return Http::response(pixelforgeGeneration($generation, 'queued'), 202);
    }]);

    runSubmitGeneration($generation);

    expect($generation->refresh())->status->toBe(GenerationStatus::Succeeded)->cost_nanos->toBe(381_887_500);
});

it('doesn\'t give up on a generation Pixelforge accepted while the last attempt timed out', function () {
    $generation = Generation::factory()->create(['created_at' => now()->subMinutes(30)->addSecond()]);
    Http::fake([CREATE_URL => function (Request $request) use ($generation) {
        deliverWebhook(pixelforgeEvent(pixelforgeGeneration($generation, 'queued')))->assertSuccessful();

        return Http::failedConnection();
    }]);

    runSubmitGeneration($generation);

    expect($generation->refresh())->status->toBe(GenerationStatus::Queued)->cost_nanos->toBeNull();
});

it('doesn\'t fail a generation Pixelforge already accepted when the job itself fails', function () {
    $generation = Generation::factory()->processing()->create();

    (new SubmitGeneration($generation))->failed(new RuntimeException('Worker died mid-request.'));

    expect($generation->refresh())->status->toBe(GenerationStatus::Processing)->cost_nanos->toBeNull();
});

it('fails a still-pending generation for free when the job itself fails', function () {
    $generation = Generation::factory()->create();

    (new SubmitGeneration($generation))->failed(new RuntimeException('Worker died mid-request.'));

    expect($generation->refresh())->status->toBe(GenerationStatus::Failed)->cost_nanos->toBe(0);
});

it('waits for a free slot, without asking Pixelforge, while 3 of ours are already running', function () {
    Generation::factory()->queued()->create();
    Generation::factory()->processing()->count(2)->create();
    $generation = Generation::factory()->create();
    Http::fake();

    runSubmitGeneration($generation)->assertReleased(delay: 5);

    Http::assertNothingSent();
    expect($generation->refresh())->status->toBe(GenerationStatus::Pending)->error_code->toBe('concurrency_limit_exceeded');
});

it('submits as soon as one of the 3 slots frees up', function () {
    Generation::factory()->processing()->count(2)->create();
    Generation::factory()->succeeded()->create();
    $generation = Generation::factory()->create();
    Http::fake([CREATE_URL => Http::response(pixelforgeGeneration($generation), 202)]);

    runSubmitGeneration($generation)->assertNotReleased();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});
