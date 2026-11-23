<?php

declare(strict_types=1);

namespace App\Enums;

enum GenerationStatus: string
{
    /** Saved locally, not yet accepted by Pixelforge (submitting, or waiting to retry). */
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /**
     * Pixelforge updates arrive duplicated and out of order, so a status only
     * ever moves forward, and never away from succeeded or failed.
     */
    public function canBecome(self $next): bool
    {
        return ! $this->isFinal() && $next->rank() > $this->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }
}
