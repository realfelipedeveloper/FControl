<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'credit_card_id', 'category_id', 'description', 'total_amount', 'installment_count', 'purchased_at'])]
class CardPurchase extends Model
{
    protected function casts(): array
    {
        return ['total_amount' => 'decimal:2', 'purchased_at' => 'date'];
    }

    public function installments(): HasMany
    {
        return $this->hasMany(CardInstallment::class);
    }

    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
