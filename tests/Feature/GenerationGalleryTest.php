<?php

declare(strict_types=1);

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(fn () => fakePixelforgePricing());

/** One generation per status, for $user, with prompts named after them. */
function generationOfEachStatus(User $user): void
{
    Generation::factory()->for($user)->create(['prompt' => 'pending']);
    Generation::factory()->for($user)->queued()->create(['prompt' => 'queued']);
    Generation::factory()->for($user)->processing()->create(['prompt' => 'processing']);
    Generation::factory()->for($user)->succeeded()->create(['prompt' => 'succeeded']);
    Generation::factory()->for($user)->failed()->create(['prompt' => 'failed']);
}

function fakePixelforgeImage(): void
{
    Http::fake(['pixelforge.promptedit.dev/images/*' => Http::response('png bytes', 200, ['Content-Type' => 'image/png'])]);
}

it('filters the gallery by status, in progress being pending, queued and processing', function (string $status, array $prompts) {
    $user = User::factory()->create();
    generationOfEachStatus($user);

    actingAs($user)->get(route('generations.index', ['status' => $status]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.status', $status)
            ->where('generations.data', fn ($generations) => collect($generations)->pluck('prompt')->sort()->values()->all() === $prompts));
})->with([
    'in progress' => ['in_progress', ['pending', 'processing', 'queued']],
    'succeeded' => ['succeeded', ['succeeded']],
    'failed' => ['failed', ['failed']],
]);

it('shows everything, instead of an error, for a status filter it doesn\'t know', function (mixed $status) {
    $user = User::factory()->create();
    generationOfEachStatus($user);

    actingAs($user)->get(route('generations.index', ['status' => $status]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.status', 'all')
            ->has('generations.data', 5));
})->with(['unknown' => 'pending', 'all' => 'all', 'an array' => [['failed']]]);

it('searches prompts, ignoring case and surrounding spaces', function () {
    $user = User::factory()->create();
    Generation::factory()->for($user)->create(['prompt' => 'A Lighthouse at dawn']);
    Generation::factory()->for($user)->create(['prompt' => 'A fox in the snow']);
    Generation::factory()->create(['prompt' => 'Someone else\'s lighthouse']);

    actingAs($user)->get(route('generations.index', ['search' => '  lighthouse ']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.search', 'lighthouse')
            ->has('generations.data', 1)
            ->where('generations.data.0.prompt', 'A Lighthouse at dawn'));
});

it('caps the search at 100 characters and ignores one that isn\'t text', function (mixed $search, string $expected) {
    actingAs(User::factory()->create())->get(route('generations.index', ['search' => $search]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.search', $expected));
})->with([
    'too long' => [str_repeat('a', 150), str_repeat('a', 100)],
    'an array' => [['fox'], ''],
]);

it('counts the user\'s whole history per tab, whatever the filters', function () {
    $user = User::factory()->create();
    generationOfEachStatus($user);
    Generation::factory()->for($user)->failed()->create();
    Generation::factory()->failed()->create();

    actingAs($user)->get(route('generations.index', ['status' => 'succeeded', 'search' => 'nothing matches']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('generations.data', 0)
            ->where('counts', ['all' => 6, 'in_progress' => 3, 'succeeded' => 1, 'failed' => 2]));
});

it('keeps the filters in the pagination links', function () {
    $user = User::factory()->create();
    Generation::factory()->for($user)->failed()->count(13)->create();

    actingAs($user)->get(route('generations.index', ['status' => 'failed', 'search' => 'light']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('generations.links.next', route('generations.index', ['status' => 'failed', 'search' => 'light', 'page' => 2])));
});

it('tells the page when a generation completed', function () {
    $user = User::factory()->create();
    Generation::factory()->for($user)->succeeded()->create(['completed_at' => '2026-11-04 14:03:29']);

    actingAs($user)->get(route('generations.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('generations.data.0.completed_at', '2026-11-04T14:03:29.000000Z'));
});

it('downloads the user\'s image as a file', function () {
    fakePixelforgeImage();
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->succeeded()->create();

    actingAs($user)->get(route('generations.download', $generation))
        ->assertOk()
        ->assertDownload("pixelforge-{$generation->id}.png")
        ->assertHeader('Content-Type', 'image/png')
        ->assertContent('png bytes');

    Http::assertSent(fn ($request) => $request->url() === 'https://pixelforge.promptedit.dev/images/example.png'
        && ! $request->hasHeader('Authorization'));
});

it('sends guests to log in instead of downloading', function () {
    get(route('generations.download', Generation::factory()->succeeded()->create()))->assertRedirect(route('login'));
});

it('doesn\'t download someone else\'s image', function () {
    actingAs(User::factory()->create())
        ->get(route('generations.download', Generation::factory()->succeeded()->create()))
        ->assertNotFound();

    Http::assertNothingSent();
});

it('only fetches images from Pixelforge', function (string $url) {
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->succeeded()->create(['image_url' => $url]);

    actingAs($user)->get(route('generations.download', $generation))->assertNotFound();

    Http::assertNothingSent();
})->with([
    'another host' => 'https://169.254.169.254/latest/meta-data',
    'plain http' => 'http://pixelforge.promptedit.dev/images/example.png',
    'a lookalike host' => 'https://pixelforge.promptedit.dev.example.com/images/example.png',
]);

it('has nothing to download without a live image', function (Closure $generation) {
    $user = User::factory()->create();

    actingAs($user)->get(route('generations.download', $generation($user)))->assertNotFound();

    Http::assertNothingSent();
})->with([
    'in flight' => [fn (User $user) => Generation::factory()->for($user)->processing()->create()],
    'failed' => [fn (User $user) => Generation::factory()->for($user)->failed()->create()],
    'link expired' => [fn (User $user) => Generation::factory()->for($user)->succeeded()->create(['image_expires_at' => now()->subMinute()])],
]);

it('says so when Pixelforge won\'t hand over the image', function (mixed $response) {
    Http::fake(['pixelforge.promptedit.dev/images/*' => $response]);
    $user = User::factory()->create();

    actingAs($user)->get(route('generations.download', Generation::factory()->for($user)->succeeded()->create()))
        ->assertStatus(502);
})->with([
    'unreachable' => fn () => Http::failedConnection(),
    'refused' => fn () => Http::response('', 403),
]);
