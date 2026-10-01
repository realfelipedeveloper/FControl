<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'name', 'color'])]
class Tag extends Model
{
    public function transactions()
    {
        return $this->belongsToMany(Transaction::class);
    }
}
