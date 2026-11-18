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

/**
 * @property-read Money $estimatedChargeMax
 * @property-read Money|null $providerCost
 * @property-read Money|null $charged
 */
#[UseFactory(GenerationFactory::class)]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
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
            'settled_at' => 'immutable_datetime',
        ];
    }

    /*
     * `billed_on_utc` is deliberately NOT cast to a date.
     *
     * It is a plain 'Y-m-d' string, which is exactly the key GET /v1/usage uses
     * for its daily rows. Keeping it as that string makes the report's GROUP BY
     * and its match against the provider's rows a direct comparison, and avoids
     * the subtle trap that a date cast writes '2026-11-17 00:00:00' into the
     * column -- which then never matches a bare 'Y-m-d' range on SQLite.
     */

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

    // -- Money accessors -----------------------------------------------------

    /** The rate snapshot this generation must always be costed from. */
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

    /**
     * The exact body to send to POST /v1/generations for this generation.
     *
     * Always derived from the stored row, never from request input, so every
     * attempt and every idempotent replay sends identical parameters. Sending
     * the same key with different parameters is a 409, so this single source is
     * what makes retries safe.
     *
     * @return array<string, mixed>
     */
    public function providerPayload(): array
    {
        return [
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
            'reference_images' => array_values($this->reference_images ?? []),
            // Returned on the generation and in every webhook, which is how a
            // webhook that overtakes the create response still finds this row.
            'metadata' => ['generation_id' => (string) $this->getKey()],
        ];
    }

    // -- State ---------------------------------------------------------------

    public function isSettled(): bool
    {
        return $this->status->isFinal();
    }

    public function imageHasExpired(): bool
    {
        return $this->image_url_expires_at !== null
            && $this->image_url_expires_at->isPast();
    }

    /**
     * Whether an incoming status may be applied, given what we already know.
     *
     * Monotonic by rank, with the event timestamp breaking ties at the same
     * rank. This is what makes a retried `generation.processing` arriving after
     * `generation.succeeded` a no-op instead of a regression.
     */
    public function canTransitionTo(GenerationStatus $incoming, ?\DateTimeInterface $eventAt): bool
    {
        // A provider-final state is absolute: "never move a generation back from
        // succeeded or failed". No timestamp can reopen it, so a redelivered
        // success with a fresher timestamp is still a no-op -- and a success can
        // never overwrite a failure, or vice versa.
        if ($this->status === GenerationStatus::Succeeded || $this->status === GenerationStatus::Failed) {
            return false;
        }

        // `Rejected` is different: it is *our* conclusion that nothing exists
        // provider-side, not something the provider told us. If the provider
        // later reports a real outcome, that is the truth and it wins -- which
        // also closes the hole where a very late webhook would otherwise leave us
        // under-billed against GET /v1/usage.
        if ($this->status === GenerationStatus::Rejected) {
            return $incoming === GenerationStatus::Succeeded
                || $incoming === GenerationStatus::Failed;
        }

        if ($incoming->rank() !== $this->status->rank()) {
            return $incoming->rank() > $this->status->rank();
        }

        // Same non-final rank: only a strictly newer event wins, so a duplicate
        // delivery of the same event changes nothing.
        return $eventAt !== null
            && $this->status_event_at !== null
            && $eventAt > $this->status_event_at;
    }

    // -- Scopes --------------------------------------------------------------

    /** Generations the provider counts against its 3-in-flight cap. */
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

    /**
     * Succeeded and therefore billed, bucketed by the provider's own UTC day.
     *
     * Matches "a generation is billed on the UTC day it completed, not the day it
     * was created" -- getting this wrong is the likeliest cause of a report that
     * almost, but not quite, ties out to GET /v1/usage.
     */
    #[Scope]
    protected function billedBetweenUtc(Builder $query, string $startDate, string $endDate): void
    {
        $query->where('status', GenerationStatus::Succeeded)
            ->whereBetween('billed_on_utc', [$startDate, $endDate]);
    }
}
