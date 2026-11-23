<?php

declare(strict_types=1);

namespace App\Pixelforge;

use App\Models\Generation;
use Closure;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Pixelforge API. Every failure surfaces as a PixelforgeException.
 */
class PixelforgeClient
{
    public function __construct(
        #[Config('services.pixelforge.url')] private readonly string $url,
        #[Config('services.pixelforge.key')] private readonly string $key,
    ) {}

    /** @return array<string, mixed> */
    public function pricing(): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('pricing'));
    }

    /**
     * Our generation id is the Idempotency-Key, so a retry can never create a second
     * Pixelforge generation. It also rides along in metadata, so webhooks can find the
     * generation even when they arrive before this request returns.
     *
     * @return array<string, mixed>
     */
    public function createGeneration(Generation $generation): array
    {
        return $this->send(fn (PendingRequest $http) => $http
            ->withHeader('Idempotency-Key', $generation->id)
            ->post('generations', [
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'reference_images' => $generation->reference_images,
                'metadata' => ['generation_id' => $generation->id],
            ]));
    }

    /** @return array<string, mixed> */
    public function generation(string $id): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get("generations/{$id}"));
    }

    /** @return array<string, mixed> */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));
    }

    /**
     * @param  Closure(PendingRequest): Response  $request
     * @return array<string, mixed>
     */
    private function send(Closure $request): array
    {
        try {
            $response = $request(Http::baseUrl($this->url)->withToken($this->key)->acceptJson()->timeout(15));
        } catch (ConnectionException $e) {
            throw new PixelforgeException('connection_failed', 'Could not reach Pixelforge.', 0, previous: $e);
        }

        if ($response->failed()) {
            throw PixelforgeException::fromResponse($response);
        }

        $json = $response->json();

        if (! is_array($json)) {
            // Like a dropped connection (status 0): we can't tell what Pixelforge did.
            throw new PixelforgeException('unreadable_response', 'Pixelforge sent a response we could not read.', 0);
        }

        return $json;
    }
}
