<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Default state: a pending medium 1024x1024 generation with a 12-character prompt.
 * Costs are what Pricing::estimate() gives at Pixelforge's published rates.
 *
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'prompt' => 'A lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'markup_percent' => 25,
            'estimated_cost_nanos' => 72_206_250,
            'max_cost_nanos' => 86_275_000,
        ];
    }

    public function queued(): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::Queued,
            'provider_id' => 'gen_'.fake()->unique()->regexify('[A-Z0-9]{26}'),
            'rates' => self::rates(),
        ]);
    }

    public function processing(): static
    {
        return $this->queued()->state(fn () => ['status' => GenerationStatus::Processing]);
    }

    public function succeeded(int $costNanos = 80_000_000, int $providerCostNanos = 64_000_000): static
    {
        return $this->queued()->state(fn () => [
            'status' => GenerationStatus::Succeeded,
            'cost_nanos' => $costNanos,
            'provider_cost_nanos' => $providerCostNanos,
            'image_url' => 'https://pixelforge.promptedit.dev/images/example.png',
            'image_expires_at' => now()->addDay(),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->queued()->state(fn () => [
            'status' => GenerationStatus::Failed,
            'cost_nanos' => 0,
            'provider_cost_nanos' => 0,
            'error_code' => 'generation_failed',
            'error_message' => 'The model could not produce an image for this prompt.',
            'completed_at' => now(),
        ]);
    }

    /** @return array<string, string> */
    public static function rates(): array
    {
        return [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ];
    }
}
