<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\CreditEntryResource;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** The user's statement of account: every purchase and every generation, with what it did to their credits. */
class CreditController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $bought = (int) $user->creditPurchases()->sum('amount_nanos');
        $spent = (int) $user->generations()->sum('cost_nanos');

        return Inertia::render('credits/Index', [
            'entries' => CreditEntryResource::collection($this->ledger($user)->paginate(25)),
            'totals' => ['bought_nanos' => $bought, 'spent_nanos' => $spent, 'balance_nanos' => $bought - $spent],
        ]);
    }

    /**
     * Purchases and generations as one list of signed amounts, newest first. A generation takes
     * what it cost once it's done (nothing if it failed) and, while in flight, holds the most it
     * can cost, the same way User::availableNanos() counts it.
     */
    private function ledger(User $user): Builder
    {
        // Both halves need the same column types for Postgres: purchase ids are numbers and
        // generation ids ULIDs, so both become strings.
        $purchases = DB::table('credit_purchases')
            ->where('user_id', $user->id)
            ->selectRaw("'purchase' as type, CAST(id AS VARCHAR(26)) as id, NULL as status, NULL as prompt, amount_nanos, created_at");

        $generations = DB::table('generations')
            ->where('user_id', $user->id)
            ->selectRaw("'generation' as type, CAST(id AS VARCHAR(26)) as id, status, SUBSTR(prompt, 1, 120) as prompt, -COALESCE(cost_nanos, max_cost_nanos) as amount_nanos, created_at");

        return DB::query()
            ->fromSub($purchases->unionAll($generations), 'entries')
            ->orderByDesc('created_at')
            // Breaks ties within a second, so no line shows up on two pages.
            ->orderByDesc('id');
    }
}
