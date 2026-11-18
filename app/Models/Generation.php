<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Pixelforge\Money;
use App\Pixelforge\Rates;
use App\Pixelforge\TokenUsage;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(GenerationFactory::class)]
class Generation extends Model
{
    use HasFactory, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'size' => GenerationSize::class,
            'quality' => GenerationQuality::class,
            'reference_images' => 'array',
            'rates_snapshot' => 'array',
            'usage' => 'array',
            'submit_outcome_unknown' => 'boolean',
            'submit_attempts' => 'integer',
            'status_event_at' => 'immutable_datetime',
            'image_url_expires_at' => 'immutable_datetime',
            'provider_created_at' => 'immutable_datetime',
            'provider_started_at' => 'immutable_datetime',
            'provider_completed_at' => 'immutable_datetime',
            'first_submit_attempted_at' => 'immutable_datetime',
            'submit_claimed_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(WebhookEvent::class);
    }

    public function rates(): Rates
    {
        return Rates::fromArray($this->rates_snapshot);
    }

    public function tokenUsage(): ?TokenUsage
    {
        return $this->usage === null ? null : TokenUsage::fromArray($this->usage);
    }

    public function estimatedChargeMax(): Money
    {
        return Money::fromNanodollars($this->estimated_charge_max_nanodollars);
    }

    public function estimatedChargeExpected(): Money
    {
        return Money::fromNanodollars($this->estimated_charge_expected_nanodollars);
    }

    public function providerCost(): ?Money
    {
        return $this->provider_cost_nanodollars === null
            ? null
            : Money::fromNanodollars($this->provider_cost_nanodollars);
    }

    public function charged(): ?Money
    {
        return $this->charged_nanodollars === null
            ? null
            : Money::fromNanodollars($this->charged_nanodollars);
    }

    public function hold(): Money
    {
        return Money::fromNanodollars($this->hold_nanodollars);
    }

    public function providerPayload(): array
    {
        return [
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
            'reference_images' => array_values($this->reference_images ?? []),

            'metadata' => ['generation_id' => (string) $this->getKey()],
        ];
    }

    public function isSettled(): bool
    {
        return $this->status->isFinal();
    }

    public function imageHasExpired(): bool
    {
        return $this->image_url_expires_at !== null
            && $this->image_url_expires_at->isPast();
    }

    public function canTransitionTo(GenerationStatus $incoming, ?\DateTimeInterface $eventAt): bool
    {
        if ($this->status === GenerationStatus::Succeeded || $this->status === GenerationStatus::Failed) {
            return false;
        }

        if ($this->status === GenerationStatus::Rejected) {
            return $incoming === GenerationStatus::Succeeded
                || $incoming === GenerationStatus::Failed;
        }

        if ($incoming->rank() !== $this->status->rank()) {
            return $incoming->rank() > $this->status->rank();
        }

        return $eventAt !== null
            && $this->status_event_at !== null
            && $eventAt > $this->status_event_at;
    }

    #[Scope]
    protected function inFlightAtProvider(Builder $query): void
    {
        $query->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing]);
    }

    #[Scope]
    protected function settled(Builder $query): void
    {
        $query->whereIn('status', [
            GenerationStatus::Succeeded,
            GenerationStatus::Failed,
            GenerationStatus::Rejected,
        ]);
    }

    #[Scope]
    protected function awaitingProvider(Builder $query): void
    {
        $query->whereIn('status', [
            GenerationStatus::Pending,
            GenerationStatus::Unknown,
            GenerationStatus::Queued,
            GenerationStatus::Processing,
        ]);
    }

    #[Scope]
    protected function billedBetweenUtc(Builder $query, string $startDate, string $endDate): void
    {
        $query->where('status', GenerationStatus::Succeeded)
            ->whereBetween('billed_on_utc', [$startDate, $endDate]);
    }
}
