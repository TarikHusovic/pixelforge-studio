<?php

declare(strict_types=1);

use App\Models\Generation;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * The statement's lines on the page as [type, id, amount_nanos].
 *
 * @return list<array{string, string, int}>
 */
function statementLines(TestResponse $response): array
{
    return array_map(
        fn (array $entry) => [$entry['type'], $entry['id'], $entry['amount_nanos']],
        $response->inertiaProps('entries.data'),
    );
}

it('sends guests to log in', function () {
    get(route('credits.index'))->assertRedirect(route('login'));
});

it('shows only the user\'s own purchases and generations', function () {
    $user = User::factory()->create();
    $purchase = $user->creditPurchases()->create(['amount_nanos' => 5_000_000_000]);
    $generation = Generation::factory()->for($user)->succeeded()->create();
    userWithCredits();
    Generation::factory()->succeeded()->create();

    $response = actingAs($user)->get(route('credits.index'))->assertOk();

    expect(array_column(statementLines($response), 1))->toEqualCanonicalizing([(string) $purchase->id, $generation->id]);
});

it('signs each line by what it does to the balance', function (Closure $create, int $amountNanos) {
    $user = User::factory()->create();
    $create($user);

    actingAs($user)->get(route('credits.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('credits/Index')
            ->has('entries.data', 1)
            ->where('entries.data.0.amount_nanos', $amountNanos));
})->with([
    'a purchase adds what was bought' => [
        fn (User $user) => $user->creditPurchases()->create(['amount_nanos' => 10_000_000_000]),
        10_000_000_000,
    ],
    'a succeeded generation takes what it cost' => [
        fn (User $user) => Generation::factory()->for($user)->succeeded(costNanos: 80_000_000)->create(),
        -80_000_000,
    ],
    'a pending generation holds the most it can cost' => [
        fn (User $user) => Generation::factory()->for($user)->create(['max_cost_nanos' => 86_275_000]),
        -86_275_000,
    ],
    'a processing generation holds the most it can cost' => [
        fn (User $user) => Generation::factory()->for($user)->processing()->create(['max_cost_nanos' => 86_275_000]),
        -86_275_000,
    ],
    'a failed generation is free' => [
        fn (User $user) => Generation::factory()->for($user)->failed()->create(),
        0,
    ],
]);

it('lists the newest first', function () {
    $user = User::factory()->create();
    $purchase = $user->creditPurchases()->forceCreate(['amount_nanos' => 5_000_000_000, 'created_at' => now()->subMinutes(2)]);
    $older = Generation::factory()->for($user)->failed()->create(['created_at' => now()->subMinute()]);
    $newer = Generation::factory()->for($user)->succeeded(costNanos: 80_000_000)->create();

    $response = actingAs($user)->get(route('credits.index'));

    expect(statementLines($response))->toBe([
        ['generation', $newer->id, -80_000_000],
        ['generation', $older->id, 0],
        ['purchase', (string) $purchase->id, 5_000_000_000],
    ]);
});

it('pages 25 lines at a time without repeating any, even within one second', function () {
    $this->freezeTime();
    $user = userWithCredits();
    Generation::factory()->for($user)->succeeded()->count(24)->create();
    $user->creditPurchases()->create(['amount_nanos' => 5_000_000_000]);
    Generation::factory()->for($user)->failed()->count(4)->create();

    $firstPage = actingAs($user)->get(route('credits.index'));
    $first = statementLines($firstPage);
    $second = statementLines(actingAs($user)->get(route('credits.index', ['page' => 2])));

    // All in one second, so only the id orders the lines: without that tie-break,
    // the database may order the two pages differently and repeat a line.
    $ids = array_column([...$first, ...$second], 1);
    $byId = $ids;
    rsort($byId, SORT_STRING);

    expect($first)->toHaveCount(25)
        ->and($second)->toHaveCount(5)
        ->and($ids)->toBe($byId)
        ->and(array_unique($ids))->toHaveCount(30)
        ->and($firstPage->inertiaProps('entries.meta.total'))->toBe(30);
});

it('sums up what the user bought and spent', function () {
    $user = userWithCredits(10_000_000_000);
    $user->creditPurchases()->create(['amount_nanos' => 5_000_000_000]);
    Generation::factory()->for($user)->succeeded(costNanos: 80_000_000)->create();
    Generation::factory()->for($user)->failed()->create();
    Generation::factory()->for($user)->processing()->create();

    actingAs($user)->get(route('credits.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('totals', [
            'bought_nanos' => 15_000_000_000,
            'spent_nanos' => 80_000_000,
            'balance_nanos' => 14_920_000_000,
        ]));
});
