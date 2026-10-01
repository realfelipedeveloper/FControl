<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Transfer;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TransferController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(Transfer::where('user_id', $request->user()->id)->with(['fromAccount', 'toAccount'])->latest('transferred_at')->paginate(min($request->integer('per_page', 15), 100)));
    }

    public function store(Request $request): JsonResponse
    {
        $owned = Rule::exists('accounts', 'id')->where('user_id', $request->user()->id)->whereNull('deleted_at');
        $data = $request->validate(['from_account_id' => ['required', $owned], 'to_account_id' => ['required', 'different:from_account_id', $owned], 'amount' => ['required', 'decimal:0,2', 'gt:0'], 'transferred_at' => ['required', 'date'], 'description' => ['nullable', 'string', 'max:180']]);
        $transfer = DB::transaction(function () use ($data, $request) {
            Account::whereKey([$data['from_account_id'], $data['to_account_id']])->lockForUpdate()->get();

            return Transfer::create([...$data, 'user_id' => $request->user()->id]);
        });
        AuditService::record($request, 'transfer.created', $transfer);

        return response()->json(['data' => $transfer->load(['fromAccount', 'toAccount']), 'message' => 'Transferência realizada.'], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => Transfer::where('user_id', $request->user()->id)->with(['fromAccount', 'toAccount'])->findOrFail($id)]);
    }
}
