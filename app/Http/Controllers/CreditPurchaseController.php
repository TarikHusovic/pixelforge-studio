<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Money;
use App\Models\CreditPurchase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CreditPurchaseController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $dollars = (int) $request->validate([
            'amount' => ['required', 'integer', Rule::in(CreditPurchase::DOLLAR_AMOUNTS)],
        ])['amount'];

        $request->user()->creditPurchases()->create(['amount_nanos' => $dollars * Money::NANOS_PER_DOLLAR]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Added \${$dollars} of credits."]);

        return back();
    }
}
