<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pixelforge delivers every event at least once, so each one is applied at most once,
 * deduped by its event id.
 */
class PixelforgeWebhookController extends Controller
{
    /** Older signatures are rejected, so a captured request can't be replayed later. */
    private const int SIGNATURE_TOLERANCE_SECONDS = 300;

    public function __invoke(Request $request): Response
    {
        $payload = $request->getContent();

        abort_unless($this->hasValidSignature((string) $request->header('Pixelforge-Signature'), $payload), 400, 'Invalid Pixelforge signature.');

        /** @var array{id: string, type: string, data?: array{object?: array<string, mixed>}} $event */
        $event = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        $object = $event['data']['object'] ?? [];

        if (($object['object'] ?? null) !== 'generation') {
            return response()->noContent(); // a ping, or an event type we don't act on
        }

        $generation = Generation::findForPixelforge($object);

        if ($generation === null) {
            // Signed with our secret, so genuine, but not one of ours: a retry won't change that.
            Log::warning('Pixelforge webhook for an unknown generation.', ['event_id' => $event['id'], 'provider_id' => $object['id'] ?? null]);

            return response()->noContent();
        }

        DB::transaction(function () use ($event, $payload, $generation, $object) {
            $firstDelivery = WebhookEvent::query()->insertOrIgnore([
                'event_id' => $event['id'],
                'type' => $event['type'],
                'generation_id' => $generation->id,
                'payload' => $payload,
                'created_at' => now(),
            ]) === 1;

            if ($firstDelivery) {
                $generation->applyUpdate($object);
            }
        });

        return response()->noContent();
    }

    /** Pixelforge-Signature is "t=<unix time>,v1=<hex HMAC-SHA256 of '<t>.<raw body>'>". */
    private function hasValidSignature(string $header, string $payload): bool
    {
        $secret = (string) config('services.pixelforge.webhook_secret');
        $parts = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $parts[trim($key)] = trim($value);
        }

        $timestamp = $parts['t'] ?? '';

        return $secret !== ''
            && ctype_digit($timestamp)
            && abs(now()->getTimestamp() - (int) $timestamp) <= self::SIGNATURE_TOLERANCE_SECONDS
            && hash_equals(hash_hmac('sha256', "{$timestamp}.{$payload}", $secret), $parts['v1'] ?? '');
    }
}
