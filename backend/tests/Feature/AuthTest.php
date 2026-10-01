<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => str_repeat('a', 64)]);
    }

    #[Test]
    public function usuario_pode_se_cadastrar_com_categorias_padrao(): void
    {
        $response = $this->postJson('/api/v1/auth/register', ['name' => 'Felipe', 'email' => 'felipe@example.com', 'password' => 'SenhaForte123', 'password_confirmation' => 'SenhaForte123']);
        $response->assertCreated()->assertJsonPath('data.user.email', 'felipe@example.com');
        $this->assertDatabaseCount('categories', 16);
    }

    #[Test]
    public function login_invalido_nao_revela_detalhes(): void
    {
        User::factory()->create(['email' => 'a@a.com']);
        $this->postJson('/api/v1/auth/login', ['email' => 'a@a.com', 'password' => 'errada'])->assertUnauthorized()->assertJsonPath('message', 'E-mail ou senha inválidos.');
    }

    #[Test]
    public function refresh_token_e_rotacionado_e_reutilizacao_revoga_familia(): void
    {
        $user = User::factory()->create(['password' => 'SenhaForte123']);
        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'SenhaForte123']);
        $plain = $login->getCookie('fcontrol_refresh', false)->getValue();
        $this->withCredentials()->withUnencryptedCookie('fcontrol_refresh', $plain)->postJson('/api/v1/auth/refresh')->assertOk();
        $this->withCredentials()->withUnencryptedCookie('fcontrol_refresh', $plain)->postJson('/api/v1/auth/refresh')->assertUnauthorized()->assertJsonPath('message', 'Reutilização de sessão detectada. Entre novamente.');
    }

    #[Test]
    public function usuario_pode_redefinir_a_senha_com_token_valido(): void
    {
        $user = User::factory()->create(['password' => 'SenhaAntiga123']);
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'SenhaNova123',
            'password_confirmation' => 'SenhaNova123',
        ])->assertOk()->assertJsonPath('message', 'Senha redefinida com sucesso.');

        $this->assertTrue(Hash::check('SenhaNova123', $user->fresh()->password));
    }
}
