<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Pricing;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('generations/Index', [
            'generations' => GenerationResource::collection(
                $request->user()->generations()->latest()->latest('id')->paginate(12),
            ),
            // Without pricing we can't quote a price, so the page shows that instead of a 500.
            'pricing' => rescue(fn () => Pricing::current()->toArray()),
        ]);
    }

    public function store(StoreGenerationRequest $request): RedirectResponse
    {
        /** @var array{prompt: string, size: string, quality: string, reference_images?: list<string>|null} $input */
        $input = $request->validated();
        $referenceImages = $input['reference_images'] ?? [];
        $estimate = Pricing::current()->estimate($input['prompt'], $input['size'], $input['quality'], count($referenceImages));

        DB::transaction(function () use ($request, $input, $referenceImages, $estimate): void {
            // Serializes this user's submissions, so two at once can't spend the same credits.
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);

            // Reserve the most it can cost, so the final charge always fits.
            if ($user->availableNanos() < $estimate['max']) {
                throw ValidationException::withMessages([
                    'credits' => 'Not enough credits for this generation. Buy credits to continue.',
                ]);
            }

            $generation = $user->generations()->create([
                'prompt' => $input['prompt'],
                'size' => $input['size'],
                'quality' => $input['quality'],
                'reference_images' => $referenceImages,
                'markup_percent' => Pricing::MARKUP_PERCENT,
                'estimated_cost_nanos' => $estimate['estimated'],
                'max_cost_nanos' => $estimate['max'],
            ]);

            // Queued inside the transaction: on the database queue the job commits with the
            // generation, so there's never a pending generation that nothing will submit.
            SubmitGeneration::dispatch($generation);
        });

        return to_route('generations.index');
    }
}
