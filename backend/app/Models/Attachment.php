<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'transaction_id', 'disk', 'path', 'original_name', 'mime_type', 'size'])]
class Attachment extends Model
{
    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}
