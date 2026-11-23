import { toast } from 'vue-sonner';
import type { Generation } from '@/types';

const BUSY = 'Pixelforge was busy, and we gave up after retrying.';

/** Keyed by error_code; anything else (submission_failed too) falls back to the stored message. */
const FAILURE_REASONS: Record<string, string> = {
    generation_failed: "Pixelforge couldn't produce an image for this prompt.",
    internal_error:
        'Pixelforge had an internal error. Trying again usually works.',
    content_policy_violation:
        "Pixelforge's content policy rejected this prompt.",
    daily_limit_exceeded:
        "Pixelforge's daily limit was reached. It resets at 00:00 UTC.",
    rate_limit_exceeded: BUSY,
    concurrency_limit_exceeded: BUSY,
    overloaded: BUSY,
    connection_failed: "We couldn't reach Pixelforge.",
};

/** Why a pending generation is still waiting, keyed by the error_code of its last attempt. */
const WAITING_REASONS: Record<string, string> = {
    concurrency_limit_exceeded:
        'Waiting for a free slot: Pixelforge runs 3 generations at a time.',
    connection_failed: "Can't reach Pixelforge, retrying.",
};

const dateTime = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});

export function failureReason(generation: Generation): string {
    return (
        FAILURE_REASONS[generation.error_code ?? ''] ??
        generation.error_message ??
        'Something went wrong.'
    );
}

export function waitingReason(generation: Generation): string | null {
    return generation.status === 'pending' && generation.error_code
        ? (WAITING_REASONS[generation.error_code] ??
              'Pixelforge is busy, retrying.')
        : null;
}

export function isExpired(generation: Generation): boolean {
    return (
        generation.image_expires_at !== null &&
        new Date(generation.image_expires_at).getTime() <= Date.now()
    );
}

/** Succeeded, and Pixelforge's link to the image still works. */
export function hasImage(generation: Generation): boolean {
    return (
        generation.status === 'succeeded' &&
        generation.image_url !== null &&
        !isExpired(generation)
    );
}

export function formatDateTime(iso: string): string {
    return dateTime.format(new Date(iso));
}

/** 74_000 => "1:14" */
export function formatElapsed(milliseconds: number): string {
    const seconds = Math.max(0, Math.floor(milliseconds / 1000));

    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}

export async function copyPrompt(prompt: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(prompt);
        toast.success('Prompt copied');
    } catch {
        toast.error("Couldn't copy the prompt. Select it and copy it instead.");
    }
}
