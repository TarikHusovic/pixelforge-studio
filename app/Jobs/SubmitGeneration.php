<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Hands a pending generation to Pixelforge. While Pixelforge is busy (its 3-at-a-time
 * limit, rate limit, overload or a network error) the generation waits and retries;
 * anything Pixelforge refuses outright fails it, which costs nothing.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /** Pixelforge runs this many generations at a time per account. */
    private const int PIXELFORGE_SLOTS = 3;

    /** @var list<int> For unexpected exceptions, say the database blinking. */
    public array $backoff = [5, 15, 60];

    public function __construct(public Generation $generation) {}

    /** How long a generation may wait for Pixelforge before we give up on it. */
    public function retryUntil(): DateTimeInterface
    {
        return $this->generation->created_at->addMinutes(30);
    }

    public function handle(PixelforgeClient $pixelforge): void
    {
        if ($this->generation->status !== GenerationStatus::Pending) {
            return; // already accepted: a webhook got here before this retry did
        }

        // While our own generations fill Pixelforge's slots, wait for one to free up here,
        // instead of spending requests (and rate limit) on a 429 we know is coming.
        if ($this->pixelforgeSlotsTaken() >= self::PIXELFORGE_SLOTS) {
            $this->retryLater('concurrency_limit_exceeded', 'Waiting for one of the generations ahead of it to finish.', 5);

            return;
        }

        try {
            $this->generation->applyUpdate($pixelforge->createGeneration($this->generation));
        } catch (PixelforgeException $e) {
            $e->isRetryable()
                ? $this->retryLater($e->errorCode, $e->getMessage(), max($e->retryAfter ?? 0, min(60, 2 ** min($this->attempts(), 6))))
                : $this->generation->failSubmission($e->errorCode, $e->getMessage());
        }
    }

    public function failed(?Throwable $e): void
    {
        $this->generation->failSubmission('submission_failed', 'Something went wrong while submitting this generation.');
    }

    private function pixelforgeSlotsTaken(): int
    {
        return Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->count();
    }

    private function retryLater(string $code, string $message, int $delay): void
    {
        if (now()->addSeconds($delay)->isAfter($this->retryUntil())) {
            $this->generation->failSubmission($code, $message);

            return;
        }

        $this->generation->delaySubmission($code, $message);
        $this->release($delay);
    }
}
