<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\artisan;

function pollUrl(Generation $generation): string
{
    return "pixelforge.promptedit.dev/v1/generations/{$generation->provider_id}";
}

it('polls in-flight generations that have been quiet for over two minutes, and applies what it finds', function () {
    $queued = Generation::factory()->queued()->create(['updated_at' => now()->subMinutes(3)]);
    $processing = Generation::factory()->processing()->create(['updated_at' => now()->subMinutes(3)]);
    Http::fake([
        pollUrl($queued) => Http::response(pixelforgeGeneration($queued, 'succeeded')),
        pollUrl($processing) => Http::response(pixelforgeGeneration($processing, 'failed')),
    ]);

    artisan('generations:sync')->assertSuccessful();

    expect($queued->refresh())->status->toBe(GenerationStatus::Succeeded)->cost_nanos->toBe(381_887_500)
        ->and($processing->refresh())->status->toBe(GenerationStatus::Failed)->cost_nanos->toBe(0);
});

it('leaves alone generations it has no reason to poll', function (Closure $generation) {
    $generation();
    Http::fake();

    artisan('generations:sync')->assertSuccessful();

    Http::assertNothingSent();
})->with([
    'recently heard from' => [fn () => Generation::factory()->processing()->create(['updated_at' => now()->subMinute()])],
    'not yet at Pixelforge' => [fn () => Generation::factory()->create(['updated_at' => now()->subMinutes(3)])],
    'succeeded' => [fn () => Generation::factory()->succeeded()->create(['updated_at' => now()->subMinutes(3)])],
    'failed' => [fn () => Generation::factory()->failed()->create(['updated_at' => now()->subMinutes(3)])],
]);

it('stops for this run, without failing, when Pixelforge is busy or its answer can\'t be read', function (Closure $response) {
    $first = Generation::factory()->queued()->create(['updated_at' => now()->subMinutes(5)]);
    $second = Generation::factory()->queued()->create(['updated_at' => now()->subMinutes(4)]);
    Http::fake([pollUrl($first) => $response()]);

    artisan('generations:sync')->assertSuccessful();

    Http::assertSentCount(1);
    expect($first->refresh()->status)->toBe(GenerationStatus::Queued)
        ->and($second->refresh()->status)->toBe(GenerationStatus::Queued);
})->with([
    'rate limited' => [fn () => pixelforgeError('rate_limit_exceeded', 429, retryAfter: 30)],
    'overloaded' => [fn () => pixelforgeError('overloaded', 503, retryAfter: 2)],
    'not JSON' => [fn () => Http::response('<html>Bad gateway</html>', 200)],
]);

it('carries on with the others when Pixelforge can\'t find one', function () {
    $lost = Generation::factory()->queued()->create(['updated_at' => now()->subMinutes(5)]);
    $found = Generation::factory()->queued()->create(['updated_at' => now()->subMinutes(4)]);
    Http::fake([
        pollUrl($lost) => pixelforgeError('not_found', 404),
        pollUrl($found) => Http::response(pixelforgeGeneration($found, 'succeeded')),
    ]);

    artisan('generations:sync')->assertSuccessful();

    expect($lost->refresh()->status)->toBe(GenerationStatus::Queued)
        ->and($found->refresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('keeps going past an answer it can\'t apply, and moves that generation to the back of the line', function () {
    $broken = Generation::factory()->processing()->create(['updated_at' => now()->subMinutes(10)]);
    $fine = Generation::factory()->processing()->create(['updated_at' => now()->subMinutes(5)]);
    Http::fake([
        pollUrl($broken) => Http::response(pixelforgeGeneration($broken, 'succeeded', ['usage' => null])),
        pollUrl($fine) => Http::response(pixelforgeGeneration($fine, 'succeeded')),
    ]);

    artisan('generations:sync')->assertSuccessful();

    expect($fine->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($broken->refresh()->status)->toBe(GenerationStatus::Processing)
        ->and($broken->updated_at->isAfter(now()->subMinute()))->toBeTrue();
});
