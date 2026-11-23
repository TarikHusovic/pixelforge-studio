<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

it('charges a generation exactly once, from submission through duplicated and late webhooks', function () {
    fakePixelforgePricing();
    Http::fake(['pixelforge.promptedit.dev/v1/generations' => fn () => Http::response(pixelforgeGeneration(Generation::sole(), 'queued', ['id' => 'gen_LIFECYCLE']), 202)]);
    $user = userWithCredits(1_000_000_000);

    // The docs' example generation: max $0.457150000, estimated $0.402450000 (cost + 25%)
    actingAs($user)->post(route('generations.store'), [
        'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/style.png'],
    ])->assertSessionHasNoErrors();
    $generation = $user->generations()->sole();
    $succeeded = pixelforgeEvent(pixelforgeGeneration($generation, 'succeeded'));
    deliverWebhook(pixelforgeEvent(pixelforgeGeneration($generation, 'processing')));
    deliverWebhook($succeeded);
    deliverWebhook($succeeded);
    deliverWebhook(pixelforgeEvent(pixelforgeGeneration($generation, 'processing')));

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->estimated_cost_nanos->toBe(402_450_000)
        ->max_cost_nanos->toBe(457_150_000)
        ->cost_nanos->toBe(381_887_500)
        ->and($user->balanceNanos())->toBe(1_000_000_000 - 381_887_500)
        ->and($user->reservedNanos())->toBe(0)
        ->and(WebhookEvent::count())->toBe(3);
});
