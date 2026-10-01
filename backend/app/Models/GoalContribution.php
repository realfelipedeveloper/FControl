<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['goal_id', 'amount', 'contributed_at', 'notes'])]
class GoalContribution extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'contributed_at' => 'date'];
    }
}
