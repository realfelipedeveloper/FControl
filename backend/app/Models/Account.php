<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** @property-read string $current_balance */
#[Fillable(['user_id', 'name', 'type', 'institution', 'initial_balance', 'active', 'notes'])]
class Account extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return ['active' => 'boolean', 'initial_balance' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    protected function currentBalance(): Attribute
    {
        return Attribute::get(function (): string {
            $settled = $this->transactions()->whereRaw('COALESCE(settled_at, transaction_date) <= ?', [today()->toDateString()]);
            $income = (clone $settled)->where('type', 'income')->where('status', 'received')->sum('amount');
            $expense = (clone $settled)->where('type', 'expense')->where('status', 'paid')->sum('amount');
            $incoming = Transfer::where('to_account_id', $this->id)->whereDate('transferred_at', '<=', today())->sum('amount');
            $outgoing = Transfer::where('from_account_id', $this->id)->whereDate('transferred_at', '<=', today())->sum('amount');

            return bcsub(bcadd((string) $this->initial_balance, bcadd((string) $income, (string) $incoming, 2), 2), bcadd((string) $expense, (string) $outgoing, 2), 2);
        });
    }
}
