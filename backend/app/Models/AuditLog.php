<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'event', 'auditable_type', 'auditable_id', 'metadata', 'ip_address', 'user_agent'])]
class AuditLog extends Model
{
    public $timestamps = false;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }
}
