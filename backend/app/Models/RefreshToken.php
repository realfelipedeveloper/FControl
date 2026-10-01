<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** @property Carbon $expires_at */
#[Fillable(['user_id', 'family_id', 'token_hash', 'device_name', 'ip_address', 'user_agent', 'expires_at', 'revoked_at', 'used_at'])]
class RefreshToken extends Model
{
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
