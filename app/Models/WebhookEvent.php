<?php

declare(strict_types=1);

namespace App\Models;

use App\Pixelforge\ProviderGeneration;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function providerGeneration(): ?ProviderGeneration
    {
        $object = $this->payload['data']['object'] ?? null;

        return is_array($object) ? ProviderGeneration::fromArray($object) : null;
    }

    public function wasApplied(): bool
    {
        return $this->applied_at !== null;
    }

    #[Scope]
    protected function unapplied(Builder $query): void
    {
        $query->whereNull('applied_at')->whereNull('ignored_reason');
    }
}
