<?php

declare(strict_types=1);

use App\Models\CreditPurchase;
use App\Models\Generation;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

it('adds exactly the credits bought and says so', function (int $dollars) {
    $user = User::factory()->create();

    actingAs($user)->from(route('generations.index'))->post(route('credit-purchases.store'), ['amount' => $dollars])
        ->assertRedirect(route('generations.index'))
        ->assertInertiaFlash('toast.type', 'success');

    expect($user->balanceNanos())->toBe($dollars * 1_000_000_000);
})->with([5, 10, 25]);

it('refuses amounts the button doesn\'t offer', function (mixed $amount) {
    $user = User::factory()->create();

    actingAs($user)->post(route('credit-purchases.store'), ['amount' => $amount])->assertSessionHasErrors('amount');

    expect(CreditPurchase::count())->toBe(0);
})->with([
    'unlisted' => 7,
    'zero' => 0,
    'negative' => -5,
    'huge' => 1_000_000,
    'fractional' => '10.5',
    'not a number' => 'ten',
    'missing' => null,
]);

it('doesn\'t sell credits to guests', function () {
    post(route('credit-purchases.store'), ['amount' => 10])->assertRedirect(route('login'));

    expect(CreditPurchase::count())->toBe(0);
});

it('shares what the user can spend, and what in-flight generations hold back', function () {
    $user = userWithCredits(10 * 1_000_000_000);
    Generation::factory()->for($user)->succeeded(costNanos: 80_000_000)->create();
    Generation::factory()->for($user)->failed()->create();
    Generation::factory()->for($user)->processing()->create(['max_cost_nanos' => 86_275_000]);
    Generation::factory()->succeeded()->create();

    actingAs($user)->get(route('profile.edit'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('credits.available_nanos', 9_920_000_000 - 86_275_000)
            ->where('credits.reserved_nanos', 86_275_000));
});
