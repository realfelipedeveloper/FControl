<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['card_purchase_id', 'invoice_id', 'number', 'amount', 'competence_date'])]
class CardInstallment extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'competence_date' => 'date'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(CardPurchase::class, 'card_purchase_id');
    }
}
