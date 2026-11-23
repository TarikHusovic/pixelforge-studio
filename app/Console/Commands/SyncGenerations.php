<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Webhook delivery isn't guaranteed, so generations that have gone quiet are polled.
 * Without this, a lost generation.succeeded would leave a cost we never recorded.
 */
#[Signature('generations:sync')]
#[Description('Poll Pixelforge for in-flight generations whose webhooks are overdue')]
class SyncGenerations extends Command
{
    public function handle(PixelforgeClient $pixelforge): void
    {
        $overdue = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->where('updated_at', '<', now()->subMinutes(2))
            ->oldest('updated_at')
            ->limit(20) // well under Pixelforge's 60 requests/minute
            ->get();

        foreach ($overdue as $generation) {
            try {
                $generation->applyUpdate($pixelforge->generation((string) $generation->provider_id));
            } catch (Throwable $e) {
                report($e);

                if ($e instanceof PixelforgeException && $e->isRetryable()) {
                    return; // Pixelforge is busy: the rest wait for the next run
                }
            } finally {
                $generation->touch(); // back of the line, so a row that keeps failing can't starve the rest
            }
        }
    }
}
