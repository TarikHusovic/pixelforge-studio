# Pixelforge Studio

Generate images with [Pixelforge](https://pixelforge.promptedit.dev) and pay exactly what they cost: Pixelforge's price plus 25%.

Built with Laravel 13, Inertia 3, Vue 3.5, Tailwind 4 and Postgres for the PromptEdit senior product engineer take-home.

- **Live:** _(deployment URL)_
- **Try it:** register, buy some (fake) credits, generate. The prompt keywords `[fail]`, `[slow]`, `[duplicate]`, `[blocked]` and `[overloaded]` make Pixelforge misbehave on purpose.

## Running it locally

```bash
composer setup    # install dependencies, create .env, migrate, build assets
composer dev      # web server, queue worker, scheduler, logs and Vite together
```

Set `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` in `.env`. Use a separate Pixelforge key per environment: each key has its own webhook URL, signing secret and usage report. Webhooks need a public URL (`/api/webhooks/pixelforge`), so locally use a tunnel or rely on the polling fallback.

Make someone an operator (for the admin report) with `php artisan app:make-admin you@example.com`, or run `php artisan db:seed` for a local operator with $25 of credits (`test@example.com` / `password`).

Checks: `composer test` (Pint, PHPStan level 7, Pest) and `npm run check && npm run types:check && npm run test:js` (the last one checks the browser's price quote against the same cases as the PHP tests).

## How a generation flows

1. **Quote.** The composer shows the estimate and the most the generation can cost, live as you type ([`resources/js/lib/pricing.ts`](resources/js/lib/pricing.ts) mirrors [`app/Billing/Pricing.php`](app/Billing/Pricing.php)). Rates and token tables come from `GET /v1/pricing`, cached for 5 minutes and refreshed in the background.
2. **Reserve.** [`GenerationController@store`](app/Http/Controllers/GenerationController.php) prices the request on the server, locks the user row, and checks that the user can afford the _maximum_ cost. A generation in flight holds its maximum against the balance, so two submissions at once can't spend the same credits and the final charge always fits. The markup is saved on the generation, the way Pixelforge fixes its rates at creation, so a later markup change never reprices work already quoted.
3. **Submit.** [`SubmitGeneration`](app/Jobs/SubmitGeneration.php) sends the request from the queue. Our generation's ULID is both the `Idempotency-Key` and `metadata.generation_id`, so a retry can never create a second Pixelforge generation, and a webhook that arrives before the create call returns still finds its generation. When Pixelforge is busy (rate limit, the 3-generation concurrency limit, overload, network errors) the job backs off, honouring `Retry-After`, for up to ~5 minutes, and the card shows why it's waiting. A prompt Pixelforge refuses outright, or a used-up daily quota, fails it at once, for free.
4. **Track.** [Webhooks](app/Http/Controllers/PixelforgeWebhookController.php) are verified (HMAC-SHA256 over `timestamp.body`, constant-time compare, 5-minute replay window, rejected outright if no secret is configured), deduped by event id (a unique index), and applied by [`Generation::applyUpdate()`](app/Models/Generation.php). Webhook delivery isn't guaranteed, so [`generations:sync`](app/Console/Commands/SyncGenerations.php) runs every minute and polls generations that have gone quiet for 2 minutes.
5. **Charge.** When a generation succeeds, its cost is computed from the generation's own `usage` and `rates` (fixed at creation), and the user is charged that plus 25%. Failed generations cost nothing.

### Why duplicates and out-of-order events are harmless

The submit job, webhooks and the poller can all race on one generation. Every status change goes through `Generation::advanceTo()`, which locks the row and only lets the status move forward (`pending → queued → processing → succeeded | failed`). A final status never changes, and the charge is written once, when the generation becomes final. A redelivered event is ignored at the dedupe index, and even a _different_ event carrying the same result finds the generation already final.

One deliberate exception: if we gave up submitting (say every attempt timed out) but Pixelforge had in fact accepted the generation, Pixelforge's later webhook is allowed to override our local "failed". Otherwise we'd pay Pixelforge without recording it, and the admin report wouldn't reconcile. It's also the one way a balance can dip below zero: the credits held for it were released when we gave up.

## Money

- Every amount is an integer number of **nanodollars** (1 USD = 1,000,000,000), the same precision as Pixelforge's nine-decimal strings. Parsing ([`Money::fromDecimal`](app/Billing/Money.php)) and arithmetic never touch floats; columns are `bigint`.
- With Pixelforge's rates (at most 3 decimals per 1M tokens) every provider cost and every 25% markup is an exact whole number of nanodollars, so **nothing is rounded today**. If rates ever get finer, provider cost rounds half up to the nanodollar and the markup rounds _up_, so we never undercharge.
- **Estimate:** `ceil(characters / 4)` text tokens and the midpoint of the quality's reasoning range. **Maximum:** text tokens +15% (rounded up) and the top of the reasoning range. Both are shown before submitting; the actual cost replaces them once the generation finishes.
- The user sees amounts rounded to 4 decimals, with the exact nine-decimal figure on hover. The admin report shows exact figures throughout.

## Admin report (stretch goal)

[`/admin/report`](app/Http/Controllers/Admin/ReportController.php) shows credit spend, provider cost and profit per UTC day and per user. Like Pixelforge, a generation counts on the UTC day it **completed** (Pixelforge's own `completed_at`), not the day it was created. Each day sits next to what `GET /v1/usage` reports for this key, with a match or difference badge. The Pixelforge column is a deferred prop, so the page renders straight away and a Pixelforge outage only blanks that column.

## Balances (stretch goal)

A balance is credits bought minus what finished generations cost, and "available" also subtracts what in-flight generations hold. "Buy credits" adds $5, $10 or $25 (fake: no payment is taken). Generating is blocked, with a clear message and a buy button, when the maximum cost doesn't fit.

## Decisions and trade-offs

- **Reserve the maximum, not the estimate.** Users with a balance between the estimate and the maximum have to top up first. In exchange, the final charge always fits what was held, and simultaneous submissions can't overspend.
- **Polling, not websockets.** The history page polls every 2 seconds, and only while something on it is still in flight. That's one cheap request with no extra infrastructure.
- **Images aren't stored.** Pixelforge's signed URLs expire after 24 hours; the card says so when they do.
- **Email verification is off**, so reviewers can register and use the deployed app without a mail provider.
- **One queue job per generation, with a fixed retry budget.** With Pixelforge's 3-at-a-time limit per account, heavy traffic would need a proper submission queue (only feed Pixelforge when a slot is free) instead of every job backing off on its own.

## What I'd do next

- Store finished images in object storage before the signed URLs expire.
- Proactive throttling: track in-flight generations and hold submissions back while 3 are already running, instead of discovering the limit through 429s.
- Push status updates (Laravel Reverb) instead of polling, once there's more on the page than one list.
- Auto-retry `internal_error` failures once; Pixelforge says retrying usually works.
- Product analytics events for ClickHouse: quote shown, submitted, succeeded and failed, with cost and latency.
