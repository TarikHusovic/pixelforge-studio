<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Billing\Pricing;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
{
    /**
     * Mirrors Pixelforge's own limits, so a bad request is caught here, not after queueing.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $pricing = Pricing::current();

        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', 'string', Rule::in($pricing->sizes())],
            'quality' => ['required', 'string', Rule::in($pricing->qualities())],
            'reference_images' => ['nullable', 'array', 'max:4'],
            'reference_images.*' => ['required', 'string', 'url:http,https', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reference_images' => 'reference images',
            'reference_images.*' => 'reference image URL',
        ];
    }
}
