<?php

namespace App\Http\Controllers\Api;

use App\Enums\FinancialType;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\FinancialResource;
use App\Models\Account;
use App\Models\Budget;
use App\Models\CardInstallment;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\Goal;
use App\Models\Recurrence;
use App\Models\Tag;
use App\Models\Transaction;
use App\Services\AuditService;
use App\Services\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ResourceController extends Controller
{
    private const CONFIG = [
        'accounts' => [Account::class, ['name' => 'required|string|max:120', 'type' => 'required|in:checking,savings,cash,digital,investment,other', 'institution' => 'nullable|string|max:120', 'initial_balance' => 'required|decimal:0,2', 'active' => 'boolean', 'notes' => 'nullable|string|max:2000'], []],
        'categories' => [Category::class, ['parent_id' => 'nullable|integer', 'type' => 'required|in:income,expense', 'name' => 'required|string|max:100', 'color' => 'nullable|regex:/^#[0-9A-Fa-f]{6}$/'], ['parent']],
        'tags' => [Tag::class, ['name' => 'required|string|max:60', 'color' => 'nullable|regex:/^#[0-9A-Fa-f]{6}$/'], []],
        'transactions' => [Transaction::class, ['account_id' => 'required|integer', 'category_id' => 'nullable|integer', 'type' => 'required|in:income,expense', 'description' => 'required|string|max:180', 'amount' => 'required|decimal:0,2|gt:0', 'transaction_date' => 'required|date', 'competence_date' => 'required|date', 'due_date' => 'nullable|date', 'settled_at' => 'nullable|date', 'status' => 'required|in:planned,pending,paid,received,overdue,cancelled', 'is_fixed' => 'boolean', 'notes' => 'nullable|string|max:3000', 'tag_ids' => 'array', 'tag_ids.*' => 'integer'], ['account', 'category', 'tags', 'attachments']],
        'cards' => [CreditCard::class, ['payment_account_id' => 'required|integer', 'name' => 'required|string|max:120', 'institution' => 'nullable|string|max:120', 'credit_limit' => 'required|decimal:0,2|gt:0', 'closing_day' => 'required|integer|between:1,28', 'due_day' => 'required|integer|between:1,28', 'active' => 'boolean'], ['paymentAccount']],
        'budgets' => [Budget::class, ['category_id' => 'required|integer', 'month' => 'required|date_format:Y-m-d', 'planned_amount' => 'required|decimal:0,2|gt:0'], ['category']],
        'goals' => [Goal::class, ['name' => 'required|string|max:150', 'description' => 'nullable|string|max:2000', 'target_amount' => 'required|decimal:0,2|gt:0', 'current_amount' => 'nullable|decimal:0,2|min:0', 'target_date' => 'nullable|date', 'status' => 'required|in:active,completed,paused'], ['contributions']],
        'recurrences' => [Recurrence::class, ['frequency' => 'required|in:weekly,monthly,yearly', 'start_date' => 'required|date', 'end_date' => 'nullable|date|after_or_equal:start_date', 'max_occurrences' => 'nullable|integer|min:1', 'next_execution_at' => 'required|date', 'active' => 'boolean', 'template' => 'required|array', 'template.account_id' => 'required|integer', 'template.category_id' => 'nullable|integer', 'template.type' => 'required|in:income,expense', 'template.description' => 'required|string|max:180', 'template.amount' => 'required|decimal:0,2|gt:0', 'template.status' => 'required|in:planned,pending,paid,received', 'template.due_date' => 'nullable|date', 'template.settled_at' => 'nullable|date', 'template.is_fixed' => 'boolean', 'template.notes' => 'nullable|string|max:3000'], []],
    ];

    public function index(Request $request): JsonResponse
    {
        [$model,, $with] = $this->config($request);
        Gate::authorize('viewAny', $model);
        $query = $model::query()->where('user_id', $request->user()->id)->with($with);
        $this->filters($query, $request);
        [, $rules] = $this->config($request);
        $sort = $request->string('sort', 'created_at')->toString();
        $sort = $sort === 'created_at' || (isset($rules[$sort]) && ! str_contains($sort, '.') && ! in_array($sort, ['template', 'tag_ids'], true)) ? $sort : 'created_at';
        $direction = $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc';
        $result = $query->orderBy($sort, $direction)->orderBy('id', $direction)->paginate(max(1, min($request->integer('per_page', 15), 100)));
        if ($request->route('resource') === 'accounts') {
            $result->getCollection()->each->append('current_balance');
        }
        if ($request->route('resource') === 'cards') {
            $result->getCollection()->each(fn (CreditCard $card) => $this->appendCardLimits($card));
        }
        if ($request->route('resource') === 'budgets') {
            $result->getCollection()->each(function (Budget $budget) use ($request) {
                $month = Carbon::parse((string) $budget->month);
                $normal = (string) Transaction::where('user_id', $request->user()->id)->where('affects_metrics', true)->where('type', 'expense')->where('category_id', $budget->category_id)->whereYear('competence_date', $month->year)->whereMonth('competence_date', $month->month)->whereNot('status', 'cancelled')->sum('amount');
                $card = (string) CardInstallment::whereHas('purchase', fn ($query) => $query->where('user_id', $request->user()->id)->where('category_id', $budget->category_id))->whereYear('competence_date', $month->year)->whereMonth('competence_date', $month->month)->sum('amount');
                $realized = Money::fromCents(Money::toCents($normal) + Money::toCents($card));
                $remaining = Money::fromCents(Money::toCents((string) $budget->planned_amount) - Money::toCents($realized));
                $percentage = Money::toCents((string) $budget->planned_amount) > 0 ? round(Money::toCents($realized) / Money::toCents((string) $budget->planned_amount) * 100, 2) : 0;
                $budget->setAttribute('realized_amount', $realized);
                $budget->setAttribute('remaining_amount', $remaining);
                $budget->setAttribute('percentage', $percentage);
                $budget->setAttribute('indicator', $percentage > 100 ? 'exceeded' : ($percentage >= 80 ? 'near' : 'normal'));
            });
        }

        return response()->json($result);
    }

    public function store(Request $request): JsonResponse
    {
        [$model, $rules, $with] = $this->config($request);
        Gate::authorize('create', $model);
        $data = $request->validate($this->secureRules($rules, $request));
        $data = $this->validateFinancialData($data, $request);
        if ($model === Category::class) {
            $this->validateCategoryHierarchy($request, $data);
        }
        $tagIds = $data['tag_ids'] ?? [];
        unset($data['tag_ids']);
        $item = $model::create([...$data, 'user_id' => $request->user()->id]);
        if ($item instanceof Transaction) {
            $item->tags()->sync($tagIds);
        }
        AuditService::record($request, $request->route('resource').'.created', $item);

        return (new FinancialResource($item->load($with)))->additional(['message' => 'Registro criado com sucesso.'])->response()->setStatusCode(201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        [$model,, $with] = $this->config($request);
        $item = $model::where('user_id', $request->user()->id)->with($with)->findOrFail($id);
        Gate::authorize('view', $item);
        if ($item instanceof Account) {
            $item->append('current_balance');
        }
        if ($item instanceof CreditCard) {
            $this->appendCardLimits($item);
        }

        return (new FinancialResource($item))->response();
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id) {
            [$model, $rules, $with] = $this->config($request);
            $item = $model::where('user_id', $request->user()->id)->lockForUpdate()->findOrFail($id);
            Gate::authorize('update', $item);
            abort_if($item instanceof Transaction && ! $item->affects_metrics, 422, 'Pagamentos de fatura não podem ser alterados como lançamentos avulsos.');
            $partial = collect($this->secureRules($rules, $request))->map(fn ($rule) => is_array($rule) ? array_merge(['sometimes'], $rule) : 'sometimes|'.$rule)->all();
            if ($item instanceof Recurrence && $request->has('template')) {
                foreach ($this->secureRules($rules, $request) as $field => $rule) {
                    if (str_starts_with($field, 'template.')) {
                        $partial[$field] = $rule;
                    }
                }
            }
            $data = $request->validate($partial);
            $data = $this->validateFinancialData($data, $request, $item);
            if ($item instanceof Category) {
                $this->validateCategoryHierarchy($request, $data, $item);
            }
            $tagIds = $data['tag_ids'] ?? null;
            unset($data['tag_ids']);
            $item->update($data);
            if ($item instanceof Transaction && is_array($tagIds)) {
                $item->tags()->sync($tagIds);
            }
            AuditService::record($request, $request->route('resource').'.updated', $item);

            return (new FinancialResource($item->fresh()->load($with)))->additional(['message' => 'Registro atualizado.'])->response();
        });
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        [$model] = $this->config($request);
        $item = $model::where('user_id', $request->user()->id)->findOrFail($id);
        Gate::authorize('delete', $item);
        abort_if($item instanceof Transaction && ! $item->affects_metrics, 422, 'Pagamentos de fatura não podem ser excluídos como lançamentos avulsos.');
        $item->delete();
        AuditService::record($request, $request->route('resource').'.deleted', $item);

        return response()->json(['message' => 'Registro removido.']);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        [$model] = $this->config($request);
        abort_unless(in_array(SoftDeletes::class, class_uses_recursive($model), true), 404);
        $item = $model::withTrashed()->where('user_id', $request->user()->id)->findOrFail($id);
        Gate::authorize('restore', $item);
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
        if (in_array($request->route('resource'), ['categories', 'transactions'], true)) {
            $rules['type'] = ['required', Rule::enum(FinancialType::class)];
        }
        if ($request->route('resource') === 'transactions') {
            $rules['status'] = ['required', Rule::enum(TransactionStatus::class)];
        }
        if ($request->route('resource') === 'recurrences') {
            $rules['template'] = 'required|array:account_id,category_id,type,description,amount,status,due_date,settled_at,is_fixed,notes';
            $rules['template.type'] = ['required', Rule::enum(FinancialType::class)];
        }

        foreach (['account_id', 'payment_account_id'] as $field) {
            if (isset($rules[$field])) {
                $rules[$field] = ['required', Rule::exists('accounts', 'id')->where('user_id', $request->user()->id)->whereNull('deleted_at')];
            }
        }
        if (isset($rules['category_id'])) {
            $rules['category_id'] = [$request->route('resource') === 'budgets' ? 'required' : 'nullable', Rule::exists('categories', 'id')->where('user_id', $request->user()->id)->whereNull('deleted_at')];
        }
        if (isset($rules['parent_id'])) {
            $rules['parent_id'] = ['nullable', Rule::exists('categories', 'id')->where('user_id', $request->user()->id)->whereNull('deleted_at')];
        }
        if (isset($rules['tag_ids.*'])) {
            $rules['tag_ids.*'] = [Rule::exists('tags', 'id')->where('user_id', $request->user()->id)];
        }
        if (isset($rules['template.account_id'])) {
            $rules['template.account_id'] = ['required', Rule::exists('accounts', 'id')->where('user_id', $request->user()->id)->whereNull('deleted_at')];
            $rules['template.category_id'] = ['nullable', Rule::exists('categories', 'id')->where('user_id', $request->user()->id)->whereNull('deleted_at')];
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
                'recurrences' => ['template->description'],
                'budgets' => [],
                default => ['name'],
            };
            if ($request->route('resource') === 'budgets') {
                $query->whereHas('category', fn ($category) => $category->where('name', 'like', "%$search%"));
            }
            $query->where(function ($q) use ($fields, $search) {
                foreach ($fields as $index => $field) {
                    $index === 0 ? $q->where($field, 'like', "%$search%") : $q->orWhere($field, 'like', "%$search%");
                }
            });
        }
        [, $rules] = $this->config($request);
        foreach (array_intersect(['type', 'status', 'account_id', 'category_id', 'credit_card_id', 'active'], array_keys($rules)) as $field) {
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

    private function validateFinancialData(array $data, Request $request, ?Model $item = null): array
    {
        $resource = $request->route('resource');
        if ($resource === 'budgets' && isset($data['month'])) {
            $data['month'] = Carbon::parse($data['month'])->startOfMonth()->toDateString();
        }
        if ($resource === 'goals' && $item instanceof Goal && array_key_exists('current_amount', $data) && $item->contributions()->exists() && Money::toCents((string) ($data['current_amount'] ?? '0.00')) !== Money::toCents((string) $item->current_amount)) {
            throw ValidationException::withMessages(['current_amount' => 'Registre uma contribuição para alterar uma meta com histórico.']);
        }
        if ($resource === 'goals' && array_key_exists('current_amount', $data) && $data['current_amount'] === null) {
            $data['current_amount'] = '0.00';
        }
        if (in_array($resource, ['transactions', 'recurrences'], true)) {
            $values = $resource === 'recurrences' ? ($data['template'] ?? $item?->getAttribute('template') ?? []) : [...($item?->getAttributes() ?? []), ...$data];
            $prefix = $resource === 'recurrences' ? 'template.' : '';
            $type = $values['type'] ?? null;
            if (($type === 'income' && ($values['status'] ?? null) === 'paid') || ($type === 'expense' && ($values['status'] ?? null) === 'received')) {
                throw ValidationException::withMessages([$prefix.'status' => 'Receitas são recebidas; despesas são pagas. Revise o status.']);
            }
            if (! empty($values['category_id']) && Category::whereKey($values['category_id'])->where('type', '!=', $type)->exists()) {
                throw ValidationException::withMessages([$prefix.'category_id' => 'A categoria deve possuir o mesmo tipo do lançamento.']);
            }
        }

        return $data;
    }

    private function appendCardLimits(CreditCard $card): void
    {
        $used = (string) $card->invoices()->whereIn('status', ['open', 'closed', 'overdue'])->sum('total');
        $card->setAttribute('used_limit', $used);
        $card->setAttribute('available_limit', Money::fromCents(Money::toCents((string) $card->credit_limit) - Money::toCents($used)));
    }

    /** @param array<string, mixed> $data */
    private function validateCategoryHierarchy(Request $request, array $data, ?Category $item = null): void
    {
        if ($item && isset($data['type']) && $data['type'] !== $item->type && Category::where('parent_id', $item->id)->exists()) {
            throw ValidationException::withMessages(['type' => 'Altere ou remova as subcategorias antes de mudar o tipo.']);
        }
        $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $item?->parent_id;
        if (! $parentId) {
            return;
        }
        if ($item && (int) $parentId === $item->id) {
            throw ValidationException::withMessages(['parent_id' => 'Uma categoria não pode ser pai dela mesma.']);
        }

        $parent = Category::where('user_id', $request->user()->id)->findOrFail($parentId);
        $type = $data['type'] ?? $item?->type;
        if ($parent->type !== $type) {
            throw ValidationException::withMessages(['parent_id' => 'A categoria pai deve possuir o mesmo tipo.']);
        }

        $visited = [];
        while ($parent) {
            if ($item && $parent->id === $item->id) {
                throw ValidationException::withMessages(['parent_id' => 'A hierarquia informada criaria um ciclo.']);
            }
            if (isset($visited[$parent->id])) {
                throw ValidationException::withMessages(['parent_id' => 'A hierarquia de categorias contém um ciclo.']);
            }
            $visited[$parent->id] = true;
            $parent = $parent->parent_id ? Category::where('user_id', $request->user()->id)->find($parent->parent_id) : null;
        }
    }
}
