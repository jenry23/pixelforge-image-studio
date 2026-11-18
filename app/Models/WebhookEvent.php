<?php

declare(strict_types=1);

namespace App\Models;

use App\Pixelforge\ProviderGeneration;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A durable record of every validly-signed webhook delivery.
 *
 * `event_id` is uniquely indexed, which is what guarantees an event delivered
 * twice is never recorded (or billed) twice. We insert this row and apply the
 * state transition in one transaction, and only acknowledge the delivery after
 * that transaction commits -- so a 2xx always means the event is durably ours.
 */
class WebhookEvent extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'applied_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
        ];
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    /** The generation object carried in `data.object`, if this event has one. */
    public function providerGeneration(): ?ProviderGeneration
    {
        $object = $this->payload['data']['object'] ?? null;

        return is_array($object) ? ProviderGeneration::fromArray($object) : null;
    }

    public function wasApplied(): bool
    {
        return $this->applied_at !== null;
    }

    /** Recorded but not yet applied -- the reconciler's work queue. */
    #[Scope]
    protected function unapplied(Builder $query): void
    {
        $query->whereNull('applied_at')->whereNull('ignored_reason');
    }
}
