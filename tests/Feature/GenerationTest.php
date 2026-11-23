<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * A valid request: 'A lighthouse' at medium 1024x1024 is estimated at 72_206_250 and
 * can cost at most 86_275_000 (see GenerationFactory).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function generationRequest(array $overrides = []): array
{
    return ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'medium', 'reference_images' => [], ...$overrides];
}

beforeEach(fn () => Queue::fake());

it('sends guests to log in', function () {
    get(route('generations.index'))->assertRedirect(route('login'));
    post(route('generations.store'), generationRequest())->assertRedirect(route('login'));
});

it('lists only the user\'s own generations, newest first', function () {
    fakePixelforgePricing();
    $user = User::factory()->create();
    $older = Generation::factory()->for($user)->create(['created_at' => now()->subMinute()]);
    $newer = Generation::factory()->for($user)->create();
    Generation::factory()->create();

    actingAs($user)->get(route('generations.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('generations/Index')
            ->has('generations.data', 2)
            ->where('generations.data.0.id', $newer->id)
            ->where('generations.data.1.id', $older->id));
});

it('gives the page Pixelforge\'s pricing and our markup for the live estimate', function () {
    fakePixelforgePricing();

    actingAs(User::factory()->create())->get(route('generations.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pricing.markup_percent', 25)
            ->where('pricing.rates.output_tokens', 40_000_000_000)
            ->where('pricing.tokens.output.high.1536x1024', 6450));
});

it('shows the user their estimated and actual cost', function () {
    fakePixelforgePricing();
    $user = User::factory()->create();
    Generation::factory()->for($user)->succeeded(costNanos: 80_000_000)->create();

    actingAs($user)->get(route('generations.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('generations.data.0.status', 'succeeded')
            ->where('generations.data.0.estimated_cost_nanos', 72_206_250)
            ->where('generations.data.0.cost_nanos', 80_000_000));
});

it('never shows the user what Pixelforge charged us', function () {
    fakePixelforgePricing();
    $user = User::factory()->create();
    Generation::factory()->for($user)->succeeded()->create(['usage' => ['output_tokens' => 1100]]);

    actingAs($user)->get(route('generations.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('generations.data.0')
            ->missing('generations.data.0.provider_cost_nanos')
            ->missing('generations.data.0.rates')
            ->missing('generations.data.0.usage'));
});

it('still shows the page, without pricing, when Pixelforge\'s pricing is unavailable', function () {
    Http::fake(['pixelforge.promptedit.dev/v1/pricing' => pixelforgeError('overloaded', 503, retryAfter: 2)]);

    actingAs(User::factory()->create())->get(route('generations.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('pricing', null));
});

it('rejects a request Pixelforge would refuse', function (array $input, string $field) {
    fakePixelforgePricing();
    $user = userWithCredits();

    actingAs($user)->post(route('generations.store'), generationRequest($input))->assertSessionHasErrors($field);

    expect(Generation::count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'no prompt' => [['prompt' => ''], 'prompt'],
    'prompt over 4000 characters' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
    'size Pixelforge doesn\'t price' => [['size' => '512x512'], 'size'],
    'quality Pixelforge doesn\'t price' => [['quality' => 'ultra'], 'quality'],
    'five reference images' => [['reference_images' => array_fill(0, 5, 'https://example.com/style.png')], 'reference_images'],
    'non-http reference image' => [['reference_images' => ['ftp://example.com/style.png']], 'reference_images.0'],
    'reference image that isn\'t a URL' => [['reference_images' => ['style.png']], 'reference_images.0'],
]);

it('accepts Pixelforge\'s limits: 4000 characters and 4 http(s) reference images', function () {
    fakePixelforgePricing();
    $user = userWithCredits();
    $images = ['http://example.com/1.png', 'https://example.com/2.png', 'https://example.com/3.png', 'https://example.com/4.png'];

    actingAs($user)->post(route('generations.store'), generationRequest(['prompt' => str_repeat('é', 4000), 'reference_images' => $images]))
        ->assertSessionHasNoErrors();

    expect($user->generations()->sole()->reference_images)->toBe($images);
});

it('records the estimate worked out on the server, not one sent by the browser', function () {
    fakePixelforgePricing();
    $user = userWithCredits();

    actingAs($user)->post(route('generations.store'), generationRequest(['estimated_cost_nanos' => 1, 'max_cost_nanos' => 1]))
        ->assertRedirect(route('generations.index'));

    expect($user->generations()->sole())
        ->status->toBe(GenerationStatus::Pending)
        ->estimated_cost_nanos->toBe(72_206_250)
        ->max_cost_nanos->toBe(86_275_000)
        ->cost_nanos->toBeNull();
});

it('queues the new generation for submission to Pixelforge', function () {
    fakePixelforgePricing();
    $user = userWithCredits();

    actingAs($user)->post(route('generations.store'), generationRequest());

    $generation = $user->generations()->sole();
    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generation->is($generation));
});

it('lets the user spend exactly what they have available', function () {
    fakePixelforgePricing();
    $user = userWithCredits(86_275_000);

    actingAs($user)->post(route('generations.store'), generationRequest())->assertSessionHasNoErrors();

    expect($user->generations()->count())->toBe(1);
});

it('refuses a generation whose max cost is more than the user has', function () {
    fakePixelforgePricing();
    $user = userWithCredits(86_274_999);

    actingAs($user)->post(route('generations.store'), generationRequest())->assertSessionHasErrors('credits');

    expect(Generation::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('holds back the max cost of generations still in flight', function (GenerationStatus $status) {
    fakePixelforgePricing();
    $user = userWithCredits(2 * 86_275_000 - 1);
    Generation::factory()->for($user)->create(['status' => $status]);

    actingAs($user)->post(route('generations.store'), generationRequest())->assertSessionHasErrors('credits');

    expect($user->generations()->count())->toBe(1);
})->with([GenerationStatus::Pending, GenerationStatus::Queued, GenerationStatus::Processing]);

it('counts finished generations at what they cost, not what they could have', function () {
    fakePixelforgePricing();
    $user = userWithCredits(86_275_000 + 80_000_000);
    Generation::factory()->for($user)->succeeded(costNanos: 80_000_000)->create();
    Generation::factory()->for($user)->failed()->create();

    actingAs($user)->post(route('generations.store'), generationRequest())->assertSessionHasNoErrors();

    expect($user->generations()->count())->toBe(3);
});

it('tells the user to try again, instead of failing, when Pixelforge pricing is down while submitting', function () {
    Http::fake(['pixelforge.promptedit.dev/v1/pricing' => pixelforgeError('overloaded', 503, retryAfter: 2)]);

    actingAs(userWithCredits())
        ->from(route('generations.index'))
        ->post(route('generations.store'), generationRequest())
        ->assertRedirect(route('generations.index'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Pixelforge is unavailable right now. Please try again in a moment.']);

    expect(Generation::query()->count())->toBe(0);
});
