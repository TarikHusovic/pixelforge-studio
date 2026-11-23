<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Hands a pending generation to Pixelforge. When Pixelforge is busy (rate, concurrency or
 * overload limits) we wait and retry with growing backoff; anything it refuses outright
 * fails the generation, which costs nothing.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /** Up to ~2 minutes of backoff in total before giving up. */
    public int $tries = 7;

    public function __construct(public Generation $generation) {}

    public function handle(PixelforgeClient $pixelforge): void
    {
        if ($this->generation->status !== GenerationStatus::Pending) {
            return; // already accepted: a webhook got here before this retry did
        }

        try {
            $this->generation->applyUpdate($pixelforge->createGeneration($this->generation));
        } catch (PixelforgeException $e) {
            if (! $e->isRetryable() || $this->attempts() >= $this->tries) {
                $this->generation->markFailed($e->errorCode, $e->getMessage());

                return;
            }

            // Lets the user see why it's still waiting; cleared once Pixelforge accepts it.
            Generation::query()
                ->whereKey($this->generation->id)
                ->where('status', GenerationStatus::Pending)
                ->update(['error_code' => $e->errorCode, 'error_message' => $e->getMessage()]);

            // Every attempt sends the same Idempotency-Key, so a retry can't create a duplicate.
            $this->release(max($e->retryAfter ?? 0, 2 ** $this->attempts()));
        }
    }

    public function failed(?Throwable $e): void
    {
        $this->generation->markFailed('submission_failed', 'Something went wrong while submitting this generation.');
    }
}
