<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property-read CreditCard $creditCard */
#[Fillable(['user_id', 'credit_card_id', 'period_start', 'period_end', 'due_date', 'status', 'total', 'paid_at'])]
class Invoice extends Model
{
    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'due_date' => 'date', 'paid_at' => 'datetime', 'total' => 'decimal:2'];
    }

    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(CardInstallment::class);
    }
}
