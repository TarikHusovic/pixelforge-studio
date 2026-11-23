import type { GenerationStatus, Nanos } from './generation';

/** A line on the user's statement (CreditEntryResource). */
export type CreditEntry = {
    type: 'purchase' | 'generation';
    /** A purchase's id, or a generation's ULID. */
    id: string;
    /** Signed: what it added (+) or took (−). An in-flight generation holds the most it can cost; a failed one is 0. */
    amount_nanos: Nanos;
    /** Generations only. */
    status: GenerationStatus | null;
    /** Generations only: the first 120 characters. */
    prompt: string | null;
    created_at: string;
};

export type CreditTotals = {
    bought_nanos: Nanos;
    /** What finished generations cost. */
    spent_nanos: Nanos;
    /** Bought minus spent. */
    balance_nanos: Nanos;
};
