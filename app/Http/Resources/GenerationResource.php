<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Pixelforge\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GenerationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_settled' => $this->status->isFinal(),

            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
            'reference_images' => $this->reference_images,

            'estimated_charge' => [
                'expected' => $this->estimatedChargeExpected()->toDisplayString(4),
                'min' => Money::fromNanodollars($this->estimated_charge_min_nanodollars)->toDisplayString(4),
                'max' => $this->estimatedChargeMax()->toDisplayString(4),
                'expected_nanodollars' => $this->estimated_charge_expected_nanodollars,
            ],

            'actual_charge' => $this->charged_nanodollars === null ? null : [
                'usd' => $this->charged()->toDisplayString(6),
                'nanodollars' => $this->charged_nanodollars,
                'exact' => $this->charged()->toUsdString(),
            ],

            'provider_cost' => $this->provider_cost_nanodollars === null ? null : [
                'usd' => $this->providerCost()->toDisplayString(6),
                'exact' => $this->providerCost()->toUsdString(),
            ],

            'usage' => $this->usage,

            'image_url' => $this->image_url,
            'image_expired' => $this->imageHasExpired(),
            'image_url_expires_at' => $this->image_url_expires_at?->toIso8601String(),

            'error_code' => $this->error_code,
            'error_message' => $this->error_message,

            'created_at' => $this->created_at->toIso8601String(),
            'completed_at' => $this->provider_completed_at?->toIso8601String(),
        ];
    }
}
