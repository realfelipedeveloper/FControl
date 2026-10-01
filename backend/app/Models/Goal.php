<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['user_id', 'name', 'description', 'target_amount', 'current_amount', 'target_date', 'status'])]
class Goal extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['target_amount' => 'decimal:2', 'current_amount' => 'decimal:2', 'target_date' => 'date'];
    }

    public function contributions()
    {
        return $this->hasMany(GoalContribution::class);
    }
}
