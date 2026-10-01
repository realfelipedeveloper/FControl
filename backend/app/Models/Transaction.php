<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $type
 * @property string $description
 * @property string $amount
 * @property string $status
 * @property Carbon $transaction_date
 * @property-read Account $account
 * @property-read Category|null $category
 */
#[Fillable(['user_id', 'account_id', 'category_id', 'recurrence_id', 'recurrence_key', 'type', 'description', 'amount', 'transaction_date', 'competence_date', 'due_date', 'settled_at', 'status', 'is_fixed', 'affects_metrics', 'notes'])]
class Transaction extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'transaction_date' => 'date', 'competence_date' => 'date', 'due_date' => 'date', 'settled_at' => 'date', 'is_fixed' => 'boolean', 'affects_metrics' => 'boolean'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }
}
