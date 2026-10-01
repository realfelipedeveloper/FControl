<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $name
 * @property int $payment_account_id
 */
#[Fillable(['user_id', 'payment_account_id', 'name', 'institution', 'credit_limit', 'closing_day', 'due_day', 'active'])]
class CreditCard extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['credit_limit' => 'decimal:2', 'active' => 'boolean'];
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
