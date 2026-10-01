<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** @property Carbon $next_execution_at
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property array<string, mixed> $template
 */
#[Fillable(['user_id', 'frequency', 'start_date', 'end_date', 'max_occurrences', 'generated_occurrences', 'next_execution_at', 'active', 'template'])]
class Recurrence extends Model
{
    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'next_execution_at' => 'date', 'active' => 'boolean', 'template' => 'array'];
    }
}
