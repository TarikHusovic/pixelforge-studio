# Pixelforge Studio

Generate images with [Pixelforge](https://pixelforge.promptedit.dev) and pay exactly what they cost: Pixelforge's price plus 25%.

Built with Laravel 13, Inertia 3, Vue 3.5, Tailwind 4 and Postgres for the PromptEdit senior product engineer take-home.

- **Live:** https://pixelforge-studio.laravel.cloud
- **Try it:** register, buy some (fake) credits, generate. The prompt keywords `[fail]`, `[slow]`, `[duplicate]`, `[blocked]` and `[overloaded]` make Pixelforge misbehave on purpose.

## Running it locally

```bash
composer setup    # install dependencies, create .env, migrate, build assets
composer dev      # web server, queue worker, scheduler, logs and Vite together
```

Set `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` in `.env`. Use a separate Pixelforge key per environment: each key has its own webhook URL, signing secret and usage report. Webhooks need a public URL (`/api/webhooks/pixelforge`), so locally use a tunnel or rely on the polling fallback.

Make someone an operator (for the admin report) with `php artisan app:make-admin you@example.com`, or run `php artisan db:seed` for a local operator with $25 of credits (`test@example.com` / `password`).

Checks: `composer test` (Pint, PHPStan level 7, Pest) and `npm run check && npm run types:check && npm run test:js` (the last one checks the browser's price quote against the same cases as the PHP tests). CI runs all of them, then the Pest suite again on Postgres 18, which production uses: SQLite accepts SQL that Postgres rejects, and skips row locks.

## How a generation flows

1. **Quote.** The composer shows the estimate and the most the generation can cost, live as you type ([`resources/js/lib/pricing.ts`](resources/js/lib/pricing.ts) mirrors [`app/Billing/Pricing.php`](app/Billing/Pricing.php)). Rates and token tables come from `GET /v1/pricing`, cached for 5 minutes and refreshed in the background.
2. **Reserve.** [`GenerationController@store`](app/Http/Controllers/GenerationController.php) prices the request on the server, locks the user row, and checks that the user can afford the _maximum_ cost. A generation in flight holds its maximum against the balance, so two submissions at once can't spend the same credits and the final charge always fits. The markup is saved on the generation, the way Pixelforge fixes its rates at creation, so a later markup change never reprices work already quoted.
3. **Submit.** [`SubmitGeneration`](app/Jobs/SubmitGeneration.php) sends the request from the queue. Our generation's ULID is both the `Idempotency-Key` and `metadata.generation_id`, so a retry can never create a second Pixelforge generation, and a webhook that arrives before the create call returns still finds its generation. Pixelforge runs 3 generations at a time per account, so while 3 of ours are queued or processing, the job waits for a slot without calling Pixelforge at all. When Pixelforge is busy anyway (rate limit, overload, network errors) the job backs off, honouring `Retry-After`. A generation may wait like this for up to 30 minutes, and its card shows why it's waiting. A prompt Pixelforge refuses outright, or a used-up daily quota, fails it at once, for free. The job is queued in the same transaction that creates the generation, so a generation can't be left pending with nothing to submit it.
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

## The interface

The studio is one screen: the composer on the left (sticky on wide screens), the gallery on the right.

- **The price is never a surprise.** Every quality and size option shows what it would cost with the current prompt. The quote shows the estimate, the most that's held until the image is done, and a "How is this priced?" breakdown: tokens per type, Pixelforge's cost, our 25% and the total. It's computed by the same code as the quote ([`breakdown()`](resources/js/lib/pricing.ts)), so the two always agree. The Generate button carries the estimate, and when it's disabled a line under it says why.
- **What's happening is visible.** Generations in progress keep their place in the gallery with a live elapsed time and, while waiting for a free Pixelforge slot, the reason. A toast says when one finishes (with "Not charged" when it fails), and the tab title counts what's in flight. The gallery filters by status and searches prompts.
- **What went wrong is specific.** Failures map Pixelforge's error codes to plain language ("Pixelforge's content policy rejected this prompt", "Pixelforge was busy, and we gave up after retrying"), always with "Not charged" and a Try again that refills the composer.
- **Every charge is auditable.** The Credits page lists purchases, charges, holds for generations in flight and failed generations at $0, so available = bought − charged − held can be checked line by line. Buying credits asks for confirmation and shows the balance after.
- **Small things:** the composer keeps a draft (per user) across reloads; `/` jumps to the prompt and Ctrl/⌘+Enter generates; any image can be reused as a reference or its settings reused; images download through the app (Pixelforge's links expire after 24 hours, and the server only ever fetches from Pixelforge's host).
- **Accessible by default:** colour pairs pass WCAG AA in light and dark themes, statuses never rely on colour alone, status text is announced politely, everything works from the keyboard, and animation stops for people who prefer reduced motion.

The look is a "forge": paper and ink (or soot), with one hot colour, ember, kept for what's live: the Generate button and generations being forged, which have heat sweeping across them until the image develops in. Money is set in a monospace face, so amounts line up like a ledger.

## Admin report (stretch goal)

[`/admin/report`](app/Http/Controllers/Admin/ReportController.php) shows credit spend, provider cost and profit per UTC day and per user. Like Pixelforge, a generation counts on the UTC day it **completed** (Pixelforge's own `completed_at`), not the day it was created. Each day sits next to what `GET /v1/usage` reports for this key, with a match or difference badge. The Pixelforge column is a deferred prop, so the page renders straight away and a Pixelforge outage only blanks that column. A summary says whether the whole range matches Pixelforge to the nanodollar, a daily chart splits spend into provider cost and profit, and presets (today, last 7 or 30 days, this or last month) set the range.

## Balances (stretch goal)

A balance is credits bought minus what finished generations cost, and "available" also subtracts what in-flight generations hold. "Buy credits" adds $5, $10 or $25 (fake: no payment is taken). Generating is blocked, with a clear message and a buy button, when the maximum cost doesn't fit. The [Credits page](app/Http/Controllers/CreditController.php) is the statement behind those numbers: one `UNION ALL` of purchases and generations, paginated.

## Decisions and trade-offs

- **Reserve the maximum, not the estimate.** Users with a balance between the estimate and the maximum have to top up first. In exchange, the final charge always fits what was held, and simultaneous submissions can't overspend.
- **Polling, not websockets.** The studio polls every 2 seconds, and only while one of the user's generations is in flight. That's one cheap request with no extra infrastructure.
- **Images aren't stored.** Pixelforge's signed URLs expire after 24 hours; the card says so when they do.
- **Email verification is off**, so reviewers can register and use the deployed app without a mail provider.
- **Deleting an account keeps its generations** (the user is soft-deleted). They're billing records Pixelforge has too, and the report has to keep matching its usage.
- **Waiting for a free Pixelforge slot is first come, first served across all users.** A per-user cap on in-flight generations would stop one user from queueing ahead of everyone.

## What I'd do next

- Store finished images in object storage before the signed URLs expire.
- Close the last reconciliation gap: if every submit attempt for 30 minutes ends without an answer and Pixelforge's webhooks for it are lost too, we never learn that it ran. Matching `GET /v1/generations` by `metadata.generation_id` for given-up generations would catch it.
- Push status updates (Laravel Reverb) instead of polling, once there's more on the page than one list.
- Auto-retry `internal_error` failures once; Pixelforge says retrying usually works.
- Product analytics events for ClickHouse: quote shown, submitted, succeeded and failed, with cost and latency.
