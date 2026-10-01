<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\Goal;
use App\Models\Recurrence;
use App\Models\Tag;
use App\Models\Transaction;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ResourceController extends Controller
{
    private const CONFIG = [
        'accounts' => [Account::class, ['name' => 'required|string|max:120', 'type' => 'required|in:checking,savings,cash,digital,investment,other', 'institution' => 'nullable|string|max:120', 'initial_balance' => 'required|decimal:0,2', 'active' => 'boolean', 'notes' => 'nullable|string|max:2000'], []],
        'categories' => [Category::class, ['parent_id' => 'nullable|integer', 'type' => 'required|in:income,expense', 'name' => 'required|string|max:100', 'color' => 'nullable|regex:/^#[0-9A-Fa-f]{6}$/'], ['parent']],
        'tags' => [Tag::class, ['name' => 'required|string|max:60', 'color' => 'nullable|regex:/^#[0-9A-Fa-f]{6}$/'], []],
        'transactions' => [Transaction::class, ['account_id' => 'required|integer', 'category_id' => 'nullable|integer', 'type' => 'required|in:income,expense', 'description' => 'required|string|max:180', 'amount' => 'required|decimal:0,2|gt:0', 'transaction_date' => 'required|date', 'competence_date' => 'required|date', 'due_date' => 'nullable|date', 'settled_at' => 'nullable|date', 'status' => 'required|in:planned,pending,paid,received,overdue,cancelled', 'is_fixed' => 'boolean', 'notes' => 'nullable|string|max:3000', 'tag_ids' => 'array', 'tag_ids.*' => 'integer'], ['account', 'category', 'tags']],
        'cards' => [CreditCard::class, ['payment_account_id' => 'required|integer', 'name' => 'required|string|max:120', 'institution' => 'nullable|string|max:120', 'credit_limit' => 'required|decimal:0,2|gt:0', 'closing_day' => 'required|integer|between:1,28', 'due_day' => 'required|integer|between:1,28', 'active' => 'boolean'], ['paymentAccount']],
        'budgets' => [Budget::class, ['category_id' => 'required|integer', 'month' => 'required|date_format:Y-m-d', 'planned_amount' => 'required|decimal:0,2|gt:0'], ['category']],
        'goals' => [Goal::class, ['name' => 'required|string|max:150', 'description' => 'nullable|string|max:2000', 'target_amount' => 'required|decimal:0,2|gt:0', 'current_amount' => 'nullable|decimal:0,2|min:0', 'target_date' => 'nullable|date', 'status' => 'required|in:active,completed,paused'], []],
        'recurrences' => [Recurrence::class, ['frequency' => 'required|in:weekly,monthly,yearly', 'start_date' => 'required|date', 'end_date' => 'nullable|date|after_or_equal:start_date', 'max_occurrences' => 'nullable|integer|min:1', 'next_execution_at' => 'required|date', 'active' => 'boolean', 'template' => 'required|array'], []],
    ];

    public function index(Request $request): JsonResponse
    {
        [$model,, $with] = $this->config($request);
        $query = $model::query()->where('user_id', $request->user()->id)->with($with);
        $this->filters($query, $request);
        $sort = in_array($request->string('sort')->toString(), ['name', 'description', 'created_at', 'transaction_date', 'due_date', 'month', 'target_date'], true) ? $request->string('sort')->toString() : 'created_at';
        $direction = $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc';
        $result = $query->orderBy($sort, $direction)->paginate(min($request->integer('per_page', 15), 100));
        if ($request->route('resource') === 'accounts') {
            $result->getCollection()->each->append('current_balance');
        }

        return response()->json($result);
    }

    public function store(Request $request): JsonResponse
    {
        [$model, $rules, $with] = $this->config($request);
        $data = $request->validate($this->secureRules($rules, $request));
        $tagIds = $data['tag_ids'] ?? [];
        unset($data['tag_ids']);
        $item = $model::create([...$data, 'user_id' => $request->user()->id]);
        if ($item instanceof Transaction) {
            $item->tags()->sync($tagIds);
        }
        AuditService::record($request, $request->route('resource').'.created', $item);

        return response()->json(['data' => $item->load($with), 'message' => 'Registro criado com sucesso.'], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        [$model,, $with] = $this->config($request);
        $item = $model::where('user_id', $request->user()->id)->with($with)->findOrFail($id);
        if ($item instanceof Account) {
            $item->append('current_balance');
        }

        return response()->json(['data' => $item]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        [$model, $rules, $with] = $this->config($request);
        $item = $model::where('user_id', $request->user()->id)->findOrFail($id);
        $partial = collect($this->secureRules($rules, $request))->map(fn ($rule) => is_array($rule) ? array_merge(['sometimes'], $rule) : 'sometimes|'.$rule)->all();
        $data = $request->validate($partial);
        $tagIds = $data['tag_ids'] ?? null;
        unset($data['tag_ids']);
        $item->update($data);
        if ($item instanceof Transaction && is_array($tagIds)) {
            $item->tags()->sync($tagIds);
        }
        AuditService::record($request, $request->route('resource').'.updated', $item);

        return response()->json(['data' => $item->fresh()->load($with), 'message' => 'Registro atualizado.']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        [$model] = $this->config($request);
        $item = $model::where('user_id', $request->user()->id)->findOrFail($id);
        $item->delete();
        AuditService::record($request, $request->route('resource').'.deleted', $item);

        return response()->json(['message' => 'Registro removido.']);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        [$model] = $this->config($request);
        abort_unless(in_array(SoftDeletes::class, class_uses_recursive($model), true), 404);
        $item = $model::withTrashed()->where('user_id', $request->user()->id)->findOrFail($id);
        $item->restore();
        AuditService::record($request, $request->route('resource').'.restored', $item);

        return response()->json(['data' => $item, 'message' => 'Registro restaurado.']);
    }

    private function config(Request $request): array
    {
        return self::CONFIG[$request->route('resource')] ?? abort(404);
    }

    private function secureRules(array $rules, Request $request): array
    {
        foreach (['account_id', 'payment_account_id'] as $field) {
            if (isset($rules[$field])) {
                $rules[$field] = ['required', Rule::exists('accounts', 'id')->where('user_id', $request->user()->id)->whereNull('deleted_at')];
            }
        }
        if (isset($rules['category_id'])) {
            $rules['category_id'] = ['nullable', Rule::exists('categories', 'id')->where('user_id', $request->user()->id)->whereNull('deleted_at')];
        }
        if (isset($rules['parent_id'])) {
            $rules['parent_id'] = ['nullable', Rule::exists('categories', 'id')->where('user_id', $request->user()->id)->whereNull('deleted_at')];
        }
        if (isset($rules['tag_ids.*'])) {
            $rules['tag_ids.*'] = [Rule::exists('tags', 'id')->where('user_id', $request->user()->id)];
        }

        return $rules;
    }

    private function filters(Builder $query, Request $request): void
    {
        if ($search = $request->string('search')->toString()) {
            $fields = match ($request->route('resource')) {
                'transactions' => ['description', 'notes'],
                'goals' => ['name', 'description'],
                'accounts' => ['name', 'institution'],
                'cards' => ['name', 'institution'],
                default => ['name'],
            };
            $query->where(function ($q) use ($fields, $search) {
                foreach ($fields as $index => $field) {
                    $index === 0 ? $q->where($field, 'like', "%$search%") : $q->orWhere($field, 'like', "%$search%");
                }
            });
        }
        foreach (['type', 'status', 'account_id', 'category_id', 'credit_card_id', 'active'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        if ($request->filled('from')) {
            $query->whereDate($request->route('resource') === 'transactions' ? 'transaction_date' : 'created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate($request->route('resource') === 'transactions' ? 'transaction_date' : 'created_at', '<=', $request->input('to'));
        }
    }
}
