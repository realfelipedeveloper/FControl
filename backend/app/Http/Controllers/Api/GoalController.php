<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Goal;
use App\Services\AuditService;
use App\Services\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GoalController extends Controller
{
    public function contribute(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['amount' => 'required|decimal:0,2|gt:0', 'contributed_at' => 'required|date', 'notes' => 'nullable|string|max:1000']);
        $goal = Goal::where('user_id', $request->user()->id)->findOrFail($id);
        DB::transaction(function () use ($goal, $data) {
            $goal->lockForUpdate();
            $goal->contributions()->create($data);
            $new = Money::toCents((string) $goal->current_amount) + Money::toCents((string) $data['amount']);
            $goal->update(['current_amount' => Money::fromCents($new), 'status' => $new >= Money::toCents((string) $goal->target_amount) ? 'completed' : $goal->status]);
        });
        AuditService::record($request, 'goal.contribution_created', $goal);

        return response()->json(['data' => $goal->fresh()->load('contributions'), 'message' => 'Contribuição registrada.'], 201);
    }
}
