<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransactionType;
use App\Pixelforge\Money;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditTransaction extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    public function balanceDelta(): Money
    {
        return Money::fromNanodollars($this->balance_delta_nanodollars);
    }

    public function holdDelta(): Money
    {
        return Money::fromNanodollars($this->hold_delta_nanodollars);
    }

    #[Scope]
    protected function ofType(Builder $query, TransactionType $type): void
    {
        $query->where('type', $type);
    }
}
