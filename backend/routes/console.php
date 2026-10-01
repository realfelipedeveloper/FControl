<?php

use App\Models\Recurrence;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('fcontrol:materialize-recurrences', function () {
    Recurrence::where('active', true)->whereDate('next_execution_at', '<=', today())->chunkById(100, function ($items) {
        foreach ($items as $recurrence) {
            DB::transaction(function () use ($recurrence) {
                $locked = Recurrence::lockForUpdate()->findOrFail($recurrence->id);
                $date = CarbonImmutable::parse($locked->next_execution_at);
                $key = $locked->id.':'.$date->format('Y-m-d');
                /** @var array<string, mixed> $template */
                $template = $locked->template;
                Transaction::firstOrCreate(['recurrence_key' => $key], [...$template, 'user_id' => $locked->user_id, 'recurrence_id' => $locked->id, 'transaction_date' => $date->format('Y-m-d'), 'competence_date' => $date->format('Y-m-d')]);
                $locked->increment('generated_occurrences');
                $next = match ($locked->frequency) {
                    'weekly' => $date->addWeek(),'monthly' => $date->addMonthNoOverflow(),'yearly' => $date->addYearNoOverflow()
                };
                $locked->update(['next_execution_at' => $next, 'active' => ! ($locked->end_date && $next->gt($locked->end_date)) && ! ($locked->max_occurrences && $locked->generated_occurrences >= $locked->max_occurrences)]);
            });
        }
    });
})->purpose('Materializa lançamentos recorrentes de forma idempotente');

Schedule::command('fcontrol:materialize-recurrences')->dailyAt('00:15')->withoutOverlapping();
