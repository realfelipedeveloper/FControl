<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CreditCard;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FinancialRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => str_repeat('c', 64)]);
    }

    #[Test]
    public function transferencia_exige_duas_contas_do_mesmo_usuario_e_contas_distintas(): void
    {
        $user = User::factory()->create();
        $a = Account::create(['user_id' => $user->id, 'name' => 'A', 'type' => 'checking', 'initial_balance' => '100.00']);
        $b = Account::create(['user_id' => $user->id, 'name' => 'B', 'type' => 'checking', 'initial_balance' => '0.00']);
        $token = auth('api')->login($user);
        $this->withToken($token)->postJson('/api/v1/transfers', ['from_account_id' => $a->id, 'to_account_id' => $b->id, 'amount' => '25.00', 'transferred_at' => '2026-09-30'])->assertCreated();
        self::assertSame('75.00', $a->fresh()->current_balance);
        self::assertSame('25.00', $b->fresh()->current_balance);
        $this->withToken($token)->postJson('/api/v1/transfers', ['from_account_id' => $a->id, 'to_account_id' => $a->id, 'amount' => '1.00', 'transferred_at' => '2026-09-30'])->assertUnprocessable();
    }

    #[Test]
    public function compra_de_cem_reais_em_tres_parcelas_preserva_total(): void
    {
        $user = User::factory()->create();
        $account = Account::create(['user_id' => $user->id, 'name' => 'Conta', 'type' => 'checking', 'initial_balance' => '500.00']);
        $card = CreditCard::create(['user_id' => $user->id, 'payment_account_id' => $account->id, 'name' => 'Cartão', 'credit_limit' => '1000.00', 'closing_day' => 10, 'due_day' => 17]);
        $token = auth('api')->login($user);
        $response = $this->withToken($token)->postJson('/api/v1/card-purchases', ['credit_card_id' => $card->id, 'description' => 'Compra', 'total_amount' => '100.00', 'installment_count' => 3, 'purchased_at' => '2026-09-01']);
        $response->assertCreated();
        self::assertSame(['33.34', '33.33', '33.33'], $response->json('data.installments.*.amount'));
    }

    #[Test]
    public function pagamento_de_fatura_gera_um_unico_impacto_na_conta(): void
    {
        $user = User::factory()->create();
        $account = Account::create(['user_id' => $user->id, 'name' => 'Conta', 'type' => 'checking', 'initial_balance' => '500.00']);
        $card = CreditCard::create(['user_id' => $user->id, 'payment_account_id' => $account->id, 'name' => 'Cartão', 'credit_limit' => '1000.00', 'closing_day' => 10, 'due_day' => 17]);
        $invoice = Invoice::create(['user_id' => $user->id, 'credit_card_id' => $card->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'due_date' => '2026-10-17', 'status' => 'closed', 'total' => '100.00']);
        $token = auth('api')->login($user);
        $this->withToken($token)->postJson("/api/v1/invoices/{$invoice->id}/pay")->assertOk();
        self::assertSame('400.00', $account->fresh()->current_balance);
        $this->withToken($token)->postJson("/api/v1/invoices/{$invoice->id}/pay")->assertUnprocessable();
        self::assertSame('400.00', $account->fresh()->current_balance);
    }
}
