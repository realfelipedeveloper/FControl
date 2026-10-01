<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\Goal;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FinancialSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => str_repeat('b', 64)]);
    }

    public static function resources(): array
    {
        return [['accounts', Account::class], ['transactions', Transaction::class], ['cards', CreditCard::class], ['budgets', Budget::class], ['goals', Goal::class]];
    }

    #[Test,DataProvider('resources')]
    public function usuario_nao_acessa_recurso_de_outro_usuario(string $endpoint, string $model): void
    {
        [$owner,$attacker] = User::factory()->count(2)->create();
        $account = Account::create(['user_id' => $owner->id, 'name' => 'Conta', 'type' => 'checking', 'initial_balance' => '0.00']);
        $category = Category::create(['user_id' => $owner->id, 'name' => 'Lazer', 'type' => 'expense']);
        $attributes = match ($model) {
            Account::class => ['user_id' => $owner->id, 'name' => 'Privada', 'type' => 'checking', 'initial_balance' => '0.00'],Transaction::class => ['user_id' => $owner->id, 'account_id' => $account->id, 'category_id' => $category->id, 'type' => 'expense', 'description' => 'Privada', 'amount' => '10.00', 'transaction_date' => '2026-01-01', 'competence_date' => '2026-01-01', 'status' => 'paid'],CreditCard::class => ['user_id' => $owner->id, 'payment_account_id' => $account->id, 'name' => 'Privado', 'credit_limit' => '1000.00', 'closing_day' => 10, 'due_day' => 17],Budget::class => ['user_id' => $owner->id, 'category_id' => $category->id, 'month' => '2026-01-01', 'planned_amount' => '100.00'],Goal::class => ['user_id' => $owner->id, 'name' => 'Privada', 'target_amount' => '100.00', 'status' => 'active']
        };
        $item = $model::create($attributes);
        $token = auth('api')->login($attacker);
        $this->withToken($token)->getJson("/api/v1/$endpoint/{$item->id}")->assertNotFound();
    }

    #[Test]
    public function policy_confirma_propriedade_do_recurso(): void
    {
        [$owner, $attacker] = User::factory()->count(2)->create();
        $account = Account::create(['user_id' => $owner->id, 'name' => 'Privada', 'type' => 'checking', 'initial_balance' => '0.00']);

        self::assertTrue(Gate::forUser($owner)->allows('view', $account));
        self::assertFalse(Gate::forUser($attacker)->allows('view', $account));
    }
}
