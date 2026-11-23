<?php

declare(strict_types=1);

use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->travelTo('2026-11-23 12:00:00');
});

it('redirects guests to the login page', function () {
    $this->get(route('admin.report'))->assertRedirect(route('login'));
});

it('is forbidden for users who are not admins', function () {
    $this->actingAs(User::factory()->create(['is_admin' => false]))->get(route('admin.report'))->assertForbidden();
});

it('adds up billed generations per day of completion and per user', function () {
    $alice = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
    $bob = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);

    Generation::factory()->for($alice)->succeeded(80_000_000, 64_000_000)
        ->create(['created_at' => '2026-11-22 23:59:00', 'completed_at' => '2026-11-23 00:00:10']);
    Generation::factory()->for($alice)->succeeded(50_000_000, 40_000_000)
        ->create(['completed_at' => '2026-11-22 08:00:00']);
    Generation::factory()->for($bob)->succeeded(25_000_000, 20_000_000)
        ->create(['completed_at' => '2026-11-23 23:59:59']);
    Generation::factory()->for($bob)->succeeded(999_000_000, 999_000_000)
        ->create(['completed_at' => '2026-11-21 23:59:59']);
    Generation::factory()->for($bob)->failed()->create();
    Generation::factory()->for($bob)->processing()->create();

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get(route('admin.report', ['from' => '2026-11-22', 'to' => '2026-11-23']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/Report')
            ->where('days', [
                ['date' => '2026-11-22', 'generations' => 1, 'spend_nanos' => 50_000_000, 'provider_cost_nanos' => 40_000_000, 'profit_nanos' => 10_000_000],
                ['date' => '2026-11-23', 'generations' => 2, 'spend_nanos' => 105_000_000, 'provider_cost_nanos' => 84_000_000, 'profit_nanos' => 21_000_000],
            ])
            ->where('totals', ['generations' => 3, 'spend_nanos' => 155_000_000, 'provider_cost_nanos' => 124_000_000, 'profit_nanos' => 31_000_000])
            ->where('users', [
                ['id' => $alice->id, 'name' => 'Alice', 'email' => 'alice@example.com', 'generations' => 2, 'spend_nanos' => 130_000_000, 'provider_cost_nanos' => 104_000_000, 'profit_nanos' => 26_000_000],
                ['id' => $bob->id, 'name' => 'Bob', 'email' => 'bob@example.com', 'generations' => 1, 'spend_nanos' => 25_000_000, 'provider_cost_nanos' => 20_000_000, 'profit_nanos' => 5_000_000],
            ])
            ->missing('pixelforge'));
});

it('loads what Pixelforge reports for the same days, to the nanodollar', function () {
    Http::fake(['pixelforge.promptedit.dev/v1/usage*' => Http::response([
        'object' => 'usage',
        'start_date' => '2026-11-22',
        'end_date' => '2026-11-23',
        'data' => [
            ['date' => '2026-11-22', 'generations' => 3, 'input_text_tokens' => 41, 'input_image_tokens' => 0, 'output_tokens' => 3300, 'reasoning_tokens' => 2950, 'cost_usd' => '12.345678901'],
            ['date' => '2026-11-23', 'generations' => 0, 'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost_usd' => '0.000000000'],
        ],
        'total' => ['generations' => 3, 'input_text_tokens' => 41, 'input_image_tokens' => 0, 'output_tokens' => 3300, 'reasoning_tokens' => 2950, 'cost_usd' => '12.345678901'],
    ])]);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get(route('admin.report', ['from' => '2026-11-22', 'to' => '2026-11-23']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload->where('pixelforge', [
                'days' => [
                    '2026-11-22' => ['generations' => 3, 'cost_nanos' => 12_345_678_901],
                    '2026-11-23' => ['generations' => 0, 'cost_nanos' => 0],
                ],
                'total' => ['generations' => 3, 'cost_nanos' => 12_345_678_901],
            ])));

    Http::assertSent(fn (Request $request) => $request['start_date'] === '2026-11-22' && $request['end_date'] === '2026-11-23');
});

it('still renders the report when Pixelforge is down', function () {
    Exceptions::fake();
    Http::fake(['pixelforge.promptedit.dev/v1/usage*' => pixelforgeError('overloaded', 503)]);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get(route('admin.report'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('days', 30)
            ->reload(fn (AssertableInertia $reload) => $reload->missing('pixelforge'), only: 'pixelforge'));

    Exceptions::assertReported(PixelforgeException::class);
});

it('reports on a sane range whatever the query says', function (array $query, string $from, string $to, int $days) {
    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get(route('admin.report', $query))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('range', ['from' => $from, 'to' => $to])
            ->has('days', $days)
            ->where('days.0.date', $from));
})->with([
    'the last 30 days by default' => [[], '2026-10-25', '2026-11-23', 30],
    '30 days ending on the given day' => [['to' => '2026-06-30'], '2026-06-01', '2026-06-30', 30],
    'at most 92 days' => [['from' => '2026-01-01', 'to' => '2026-11-23'], '2026-08-24', '2026-11-23', 92],
    'dates in either order' => [['from' => '2026-11-20', 'to' => '2026-11-10'], '2026-11-10', '2026-11-20', 11],
    'defaults for dates that do not exist' => [['from' => 'yesterday', 'to' => '2026-02-31'], '2026-10-25', '2026-11-23', 30],
    'defaults for an empty form' => [['from' => '', 'to' => ''], '2026-10-25', '2026-11-23', 30],
    'defaults for arrays' => [['from' => ['2026-11-01']], '2026-10-25', '2026-11-23', 30],
]);
