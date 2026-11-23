/** Money is integer nanodollars (1 USD = 1_000_000_000), as sent by the server. */
export type Nanos = number;

export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

export type Generation = {
    id: string;
    status: GenerationStatus;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    estimated_cost_nanos: Nanos;
    max_cost_nanos: Nanos;
    /** What the user was charged: null while in flight, 0 when failed. */
    cost_nanos: Nanos | null;
    image_url: string | null;
    image_expires_at: string | null;
    /** On a failed generation: why. On a pending one: why we're still retrying. */
    error_code: string | null;
    error_message: string | null;
    /** True while pending, queued or processing. */
    in_flight: boolean;
    created_at: string;
};

/** A Laravel paginator wrapped in a JsonResource collection. */
export type Paginated<TItem> = {
    data: TItem[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
};

/** App\Billing\Pricing::toArray(). Rates are nanodollars per 1M tokens. */
export type Pricing = {
    markup_percent: number;
    rates: Record<string, Nanos>;
    tokens: {
        characters_per_text_token: number;
        text_token_variance: number;
        per_reference_image: number;
        output: Record<string, Record<string, number>>;
        reasoning: Record<string, { min: number; max: number }>;
    };
};

export type Credits = {
    /** What the user can spend now: the balance minus what in-flight generations hold. */
    available_nanos: Nanos;
    /** Held back for in-flight generations: the most they can still cost. */
    reserved_nanos: Nanos;
    /** Whole-dollar amounts the "Buy credits" button offers. */
    purchase_amounts: number[];
};
