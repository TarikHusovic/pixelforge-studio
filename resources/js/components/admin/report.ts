import { formatUsdExact } from '@/lib/money';
import type { Nanos } from '@/types';

/** Succeeded generations only, on the UTC day they completed. */
export type Amounts = {
    generations: number;
    spend_nanos: Nanos;
    provider_cost_nanos: Nanos;
    profit_nanos: Nanos;
};

export type ReportDay = Amounts & { date: string };

export type ReportUser = Amounts & { id: number; name: string; email: string };

export type PixelforgeUsage = { generations: number; cost_nanos: Nanos };

/** Pixelforge's GET /v1/usage for the report's days, keyed by date. */
export type PixelforgeReport = {
    days: Record<string, PixelforgeUsage>;
    total: PixelforgeUsage;
};

/** Exact and signed, so the direction of a difference is clear: "+$0.000001000". */
export function signedUsdExact(nanos: Nanos): string {
    return `${nanos > 0 ? '+' : ''}${formatUsdExact(nanos)}`;
}

/** "1 generation", "3 generations". */
export function generationCount(count: number): string {
    return `${count} ${count === 1 ? 'generation' : 'generations'}`;
}
