<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['card_purchase_id', 'invoice_id', 'number', 'amount', 'competence_date'])]
class CardInstallment extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'competence_date' => 'date'];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
