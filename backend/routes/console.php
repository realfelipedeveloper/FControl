<?php

use App\Models\Invoice;
use App\Models\Recurrence;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('fcontrol:materialize-recurrences', function () {
    Recurrence::where('active', true)->whereDate('next_execution_at', '<=', today())->chunkById(100, function ($items) {
        foreach ($items as $recurrence) {
            DB::transaction(function () use ($recurrence) {
                $locked = Recurrence::lockForUpdate()->find($recurrence->id);
                if (! $locked || ! $locked->active || $locked->next_execution_at->isAfter(today())) {
                    return;
                }
                $date = CarbonImmutable::parse($locked->next_execution_at);
                if (($locked->end_date && $date->gt($locked->end_date)) || ($locked->max_occurrences && $locked->generated_occurrences >= $locked->max_occurrences)) {
                    $locked->update(['active' => false]);

                    return;
                }
                if ($date->lt($locked->start_date)) {
                    $locked->update(['next_execution_at' => $locked->start_date]);

                    return;
                }
                $key = $locked->id.':'.$date->format('Y-m-d');
                /** @var array<string, mixed> $template */
                $template = Arr::only($locked->template, ['account_id', 'category_id', 'type', 'description', 'amount', 'status', 'due_date', 'settled_at', 'is_fixed', 'notes']);
                foreach (['due_date', 'settled_at'] as $field) {
                    if (! empty($template[$field])) {
                        $offset = CarbonImmutable::parse($locked->start_date)->diffInDays(CarbonImmutable::parse($template[$field]));
                        $template[$field] = $date->addDays((int) $offset)->toDateString();
                    }
                }
                Transaction::withTrashed()->firstOrCreate(['recurrence_key' => $key], [...$template, 'user_id' => $locked->user_id, 'recurrence_id' => $locked->id, 'transaction_date' => $date->format('Y-m-d'), 'competence_date' => $date->format('Y-m-d')]);
                $locked->increment('generated_occurrences');
                $next = match ($locked->frequency) {
                    'weekly' => $date->addWeek(),
                    'monthly' => $date->addMonthNoOverflow()->day(min($locked->start_date->day, $date->addMonthNoOverflow()->daysInMonth)),
                    'yearly' => $date->addYearNoOverflow()->day(min($locked->start_date->day, $date->addYearNoOverflow()->daysInMonth))
                };
                $locked->update(['next_execution_at' => $next, 'active' => ! ($locked->end_date && $next->gt($locked->end_date)) && ! ($locked->max_occurrences && $locked->generated_occurrences >= $locked->max_occurrences)]);
            });
        }
    });
})->purpose('Materializa lançamentos recorrentes de forma idempotente');

Schedule::command('fcontrol:materialize-recurrences')->dailyAt('00:15')->withoutOverlapping();

Schedule::call(function () {
    Invoice::where('status', 'open')->whereDate('period_end', '<', today())->update(['status' => 'closed']);
    Invoice::whereIn('status', ['open', 'closed'])->whereDate('due_date', '<', today())->update(['status' => 'overdue']);
})->dailyAt('00:10')->name('fcontrol:update-invoices')->withoutOverlapping();
