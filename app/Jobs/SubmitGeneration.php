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

    /**
     * Up to ~5 minutes of backoff in total before giving up: Pixelforge runs 3 generations
     * at a time per account, and a [slow] one can hold a slot for 90 seconds.
     */
    public int $tries = 10;

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
                $this->generation->failSubmission($e->errorCode, $e->getMessage());

                return;
            }

            $this->generation->delaySubmission($e->errorCode, $e->getMessage());
            $this->release(max($e->retryAfter ?? 0, min(60, 2 ** $this->attempts())));
        }
    }

    public function failed(?Throwable $e): void
    {
        $this->generation->failSubmission('submission_failed', 'Something went wrong while submitting this generation.');
    }
}
