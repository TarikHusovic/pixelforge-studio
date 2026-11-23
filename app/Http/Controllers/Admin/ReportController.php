<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Billing\Money;
use App\Enums\GenerationStatus;
use App\Http\Controllers\Controller;
use App\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

/**
 * Credit spend, provider cost and profit per UTC day and per user, next to what
 * Pixelforge's GET /v1/usage reports, so the provider cost can be reconciled day by day.
 */
class ReportController extends Controller
{
    /** GET /v1/usage covers the last 30 days by default and at most 92; the report does the same. */
    private const int DEFAULT_DAYS = 30;

    private const int MAX_DAYS = 92;

    private const string AGGREGATES = 'COUNT(*) as generations, SUM(generations.cost_nanos) as spend_nanos, SUM(generations.provider_cost_nanos) as provider_cost_nanos';

    public function __invoke(Request $request, PixelforgeClient $pixelforge): Response
    {
        [$from, $to] = $this->range($request);

        $byDay = $this->billed($from, $to)
            ->selectRaw('DATE(generations.completed_at) as day, '.self::AGGREGATES)
            ->groupByRaw('DATE(generations.completed_at)')
            ->get()
            ->keyBy('day');

        // Newest first, and every day, zeros included, so the rows line up with Pixelforge's.
        $days = [];
        for ($day = $to; $day >= $from; $day = $day->subDay()) {
            $days[] = ['date' => $day->toDateString(), ...self::amounts($byDay->get($day->toDateString()))];
        }

        $users = $this->billed($from, $to)
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->selectRaw('users.id, users.name, users.email, '.self::AGGREGATES)
            ->groupBy('users.id', 'users.name', 'users.email')
            ->orderByDesc('spend_nanos')
            ->orderBy('users.name')
            ->get()
            ->map(fn (stdClass $row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'email' => $row->email,
                ...self::amounts($row),
            ]);

        return Inertia::render('admin/Report', [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'days' => $days,
            'totals' => self::amounts($this->billed($from, $to)->selectRaw(self::AGGREGATES)->first()),
            'users' => $users,
            // Deferred, so the page doesn't wait on Pixelforge, and an outage only blanks its column.
            'pixelforge' => Inertia::defer(fn () => $this->pixelforgeUsage($pixelforge, $from, $to), rescue: true),
        ]);
    }

    /**
     * The UTC days to report on. Bad input falls back to the defaults instead of failing
     * validation: on a GET page, redirecting back would just loop.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function range(Request $request): array
    {
        $dates = Validator::make($request->only('from', 'to'), [
            'from' => ['date_format:Y-m-d'],
            'to' => ['date_format:Y-m-d'],
        ])->valid();

        $to = CarbonImmutable::parse($dates['to'] ?? 'today', 'UTC');
        $from = isset($dates['from']) ? CarbonImmutable::parse($dates['from'], 'UTC') : $to->subDays(self::DEFAULT_DAYS - 1);

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from->max($to->subDays(self::MAX_DAYS - 1)), $to];
    }

    /**
     * Like Pixelforge, a generation is billed on the UTC day it completed, not the day
     * it was created. Failed ones cost nothing, in-flight ones have no cost yet.
     */
    private function billed(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DB::table('generations')
            ->where('generations.status', GenerationStatus::Succeeded)
            ->where('generations.completed_at', '>=', $from)
            ->where('generations.completed_at', '<', $to->addDay());
    }

    /**
     * Postgres returns SUM(bigint) as a numeric string, and a day without generations has no row.
     *
     * @return array{generations: int, spend_nanos: int, provider_cost_nanos: int, profit_nanos: int}
     */
    private static function amounts(?stdClass $row): array
    {
        $spend = (int) $row?->spend_nanos;
        $providerCost = (int) $row?->provider_cost_nanos;

        return [
            'generations' => (int) $row?->generations,
            'spend_nanos' => $spend,
            'provider_cost_nanos' => $providerCost,
            'profit_nanos' => $spend - $providerCost,
        ];
    }

    /**
     * What Pixelforge says it charged us, per UTC day.
     *
     * @return array{days: array<string, array{generations: int, cost_nanos: int}>, total: array{generations: int, cost_nanos: int}}
     */
    private function pixelforgeUsage(PixelforgeClient $pixelforge, CarbonImmutable $from, CarbonImmutable $to): array
    {
        /** @var array{data: list<array{date: string, generations: int, cost_usd: string}>, total: array{generations: int, cost_usd: string}} $usage */
        $usage = $pixelforge->usage($from->toDateString(), $to->toDateString());

        $amounts = fn (array $row): array => [
            'generations' => (int) $row['generations'],
            'cost_nanos' => Money::toNanos($row['cost_usd']),
        ];

        return [
            'days' => collect($usage['data'])->keyBy('date')->map($amounts)->all(),
            'total' => $amounts($usage['total']),
        ];
    }
}
