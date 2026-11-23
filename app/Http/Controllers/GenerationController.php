<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Pricing;
use App\Enums\GenerationStatus;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;

class GenerationController extends Controller
{
    /** The gallery's status tabs ("all" aside), and the statuses each one shows. */
    private const array FILTERS = [
        'in_progress' => [GenerationStatus::Pending, GenerationStatus::Queued, GenerationStatus::Processing],
        'succeeded' => [GenerationStatus::Succeeded],
        'failed' => [GenerationStatus::Failed],
    ];

    private const int MAX_SEARCH_LENGTH = 100;

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        $generations = $request->user()->generations()
            ->when($filters['status'] !== 'all', fn ($query) => $query->whereIn('status', self::FILTERS[$filters['status']]))
            // Not escaped, so a % or _ in the search (or a \ on Postgres) just matches more loosely.
            ->when($filters['search'] !== '', fn ($query) => $query->whereLike('prompt', "%{$filters['search']}%"))
            ->latest()
            ->latest('id')
            ->paginate(12)
            ->withQueryString();

        return Inertia::render('generations/Index', [
            'generations' => GenerationResource::collection($generations),
            'filters' => $filters,
            'counts' => $this->counts($request->user()),
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

    /** The image itself, so the browser saves it instead of opening Pixelforge's link. */
    public function download(Request $request, Generation $generation): HttpResponse
    {
        // Someone else's generation is a 404 too, so ids can't be probed.
        abort_unless(
            $generation->user_id === $request->user()->id
                && $generation->status === GenerationStatus::Succeeded
                && $this->isPixelforgeImage($generation->image_url)
                && ! $generation->image_expires_at?->isPast(),
            404,
        );

        // The URL is signed, so no API key goes with it.
        // ponytail: buffers the image (a few MB); stream it if images get much larger.
        try {
            $image = Http::timeout(30)->get($generation->image_url)->throw();
        } catch (ConnectionException|RequestException $e) {
            report($e);
            abort(502, "Couldn't fetch the image from Pixelforge. Try again in a moment.");
        }

        return response($image->body(), headers: [
            'Content-Type' => 'image/png',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, "pixelforge-{$generation->id}.png"),
        ]);
    }

    /**
     * image_url only ever comes from Pixelforge (signed webhooks, our own API calls), but we fetch
     * it server-side, so we also refuse to fetch anything that isn't on Pixelforge's host.
     */
    private function isPixelforgeImage(?string $url): bool
    {
        $pixelforgeHost = parse_url((string) config('services.pixelforge.url'), PHP_URL_HOST);

        return $url !== null
            && parse_url($url, PHP_URL_SCHEME) === 'https'
            && parse_url($url, PHP_URL_HOST) === $pixelforgeHost;
    }

    /**
     * Bad filter input shows everything instead of failing validation: on a GET page,
     * redirecting back would just loop.
     *
     * @return array{status: string, search: string}
     */
    private function filters(Request $request): array
    {
        $status = $request->query('status');
        $search = $request->query('search');

        return [
            'status' => is_string($status) && isset(self::FILTERS[$status]) ? $status : 'all',
            'search' => is_string($search) ? mb_substr(trim($search), 0, self::MAX_SEARCH_LENGTH) : '',
        ];
    }

    /**
     * The tab counts, over the user's whole history whatever the filters.
     *
     * @return array{all: int, in_progress: int, succeeded: int, failed: int}
     */
    private function counts(User $user): array
    {
        $byStatus = $user->generations()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'all' => (int) $byStatus->sum(),
            ...array_map(
                fn (array $statuses): int => (int) $byStatus->only(array_column($statuses, 'value'))->sum(),
                self::FILTERS,
            ),
        ];
    }
}
