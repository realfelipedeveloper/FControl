<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\Category;
use App\Models\RefreshToken;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpFoundation\Cookie;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request): User {
            $user = User::create($request->safe()->only(['name', 'email', 'password']));
            $expenses = ['Moradia', 'Alimentação', 'Transporte', 'Saúde', 'Educação', 'Lazer', 'Assinaturas', 'Compras', 'Impostos', 'Outros'];
            $income = ['Salário', 'Freelancer', 'Rendimentos', 'Reembolso', 'Venda', 'Outros'];
            foreach ($expenses as $name) {
                Category::create(['user_id' => $user->id, 'type' => 'expense', 'name' => $name, 'is_default' => true]);
            }
            foreach ($income as $name) {
                Category::create(['user_id' => $user->id, 'type' => 'income', 'name' => $name, 'is_default' => true]);
            }

            return $user;
        });
        AuditService::record($request, 'auth.register', $user);

        return $this->issueTokens($request, $user, 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $token = JWTAuth::attempt($request->safe()->only(['email', 'password']));
        if (! is_string($token)) {
            AuditService::record($request, 'auth.login_failed', null, ['email_hash' => hash('sha256', strtolower($request->string('email')->toString()))]);

            return response()->json(['message' => 'E-mail ou senha inválidos.'], 401);
        }
        $user = JWTAuth::user();
        abort_unless($user instanceof User, 401);
        AuditService::record($request, 'auth.login', $user);

        return $this->issueTokens($request, $user, 200, $token);
    }

    public function refresh(Request $request): JsonResponse
    {
        $plain = $request->cookie('fcontrol_refresh');
        if (! is_string($plain) || $plain === '') {
            return response()->json(['message' => 'Sessão expirada.'], 401);
        }
        $stored = RefreshToken::where('token_hash', hash('sha256', $plain))->first();
        if (! $stored || Carbon::parse($stored->expires_at)->isPast()) {
            return response()->json(['message' => 'Sessão expirada.'], 401)->withoutCookie('fcontrol_refresh');
        }
        if ($stored->used_at || $stored->revoked_at) {
            RefreshToken::where('family_id', $stored->family_id)->update(['revoked_at' => now()]);

            return response()->json(['message' => 'Reutilização de sessão detectada. Entre novamente.'], 401)->withoutCookie('fcontrol_refresh');
        }
        $stored->update(['used_at' => now(), 'revoked_at' => now()]);

        return $this->issueTokens($request, User::findOrFail($stored->user_id), 200, null, $stored->family_id);
    }

    public function logout(Request $request): JsonResponse
    {
        if ($plain = $request->cookie('fcontrol_refresh')) {
            RefreshToken::where('token_hash', hash('sha256', $plain))->update(['revoked_at' => now()]);
        }
        auth('api')->logout();
        AuditService::record($request, 'auth.logout');

        return response()->json(['message' => 'Sessão encerrada.'])->withoutCookie('fcontrol_refresh');
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:120'], 'email' => ['sometimes', 'email', 'max:190', Rule::unique('users', 'email')->ignore($request->user()->id)], 'currency' => ['sometimes', 'string', 'size:3'], 'locale' => ['sometimes', 'in:pt-BR'], 'timezone' => ['sometimes', 'timezone']]);
        $request->user()->update($data);

        return response()->json(['data' => $request->user()->fresh(), 'message' => 'Perfil atualizado.']);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'current_password:api'], 'password' => ['required', 'confirmed', 'min:10']]);
        $request->user()->update(['password' => $data['password']]);
        RefreshToken::where('user_id', $request->user()->id)->update(['revoked_at' => now()]);
        AuditService::record($request, 'auth.password_changed', $request->user());

        return response()->json(['message' => 'Senha alterada. Entre novamente nos outros dispositivos.']);
    }

    public function sessions(Request $request): JsonResponse
    {
        return response()->json(['data' => RefreshToken::where('user_id', $request->user()->id)->whereNull('revoked_at')->where('expires_at', '>', now())->latest()->get(['id', 'family_id', 'device_name', 'ip_address', 'user_agent', 'expires_at', 'created_at'])]);
    }

    public function revokeSession(Request $request, int $id): JsonResponse
    {
        $session = RefreshToken::where('user_id', $request->user()->id)->findOrFail($id);
        RefreshToken::where('family_id', $session->family_id)->update(['revoked_at' => now()]);
        AuditService::record($request, 'auth.session_revoked');

        return response()->json(['message' => 'Sessão revogada.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink($request->only('email'));

        return response()->json(['message' => 'Se o e-mail existir, enviaremos as instruções de recuperação.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'min:10'],
        ]);
        $status = Password::reset($data, function (User $user, string $password): void {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            RefreshToken::where('user_id', $user->id)->update(['revoked_at' => now()]);
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => __($status)], 422);
        }

        return response()->json(['message' => 'Senha redefinida com sucesso.']);
    }

    private function issueTokens(Request $request, User $user, int $status, ?string $accessToken = null, ?string $familyId = null): JsonResponse
    {
        $accessToken ??= JWTAuth::fromUser($user);
        $plain = Str::random(80);
        RefreshToken::create(['user_id' => $user->id, 'family_id' => $familyId ?? (string) Str::uuid(), 'token_hash' => hash('sha256', $plain), 'device_name' => $request->input('device_name'), 'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000), 'expires_at' => now()->addDays(30)]);
        $cookie = Cookie::create('fcontrol_refresh', $plain, now()->addDays(30), '/api/v1/auth', null, app()->isProduction(), true, false, Cookie::SAMESITE_STRICT);

        return response()->json(['data' => ['access_token' => $accessToken, 'token_type' => 'bearer', 'expires_in' => (int) config('jwt.ttl', 15) * 60, 'user' => $user]], $status)->withCookie($cookie);
    }
}
