<?php

namespace App\Services\Aplicativo;

use App\Models\Aplicativo\TokenAplicativo;
use App\Models\Usuario;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Login do aplicativo Android pelo portal:
 * 1. o app abre /app/entrar?desafio=X no navegador do celular (X = SHA-256 em base64url de um segredo que só o app
 *    conhece, como no PKCE);
 * 2. o portal faz o login normal pelo Entra ID e, se o usuário já existia e tem acesso, gera um código de uso único
 *    (vale config('oee.app.minutos_codigo') minutos) e devolve o navegador para o app (br.com.alumbra.oee://login);
 * 3. o app troca o código + o segredo por um token em POST /api/app/token.
 */
class LoginAplicativo
{
    public const ESQUEMA_RETORNO = 'br.com.alumbra.oee://login';

    public function criarCodigo(Usuario $usuario, string $desafio): string
    {
        $codigo = Str::random(48);
        Cache::put($this->chave($codigo), ['usuario_id' => $usuario->id, 'desafio' => $desafio],
            now()->addMinutes((int) config('oee.app.minutos_codigo', 3)));

        return $codigo;
    }

    /** Troca o código (uso único) pelo token. Devolve null se o código ou o segredo não conferem. */
    public function trocarCodigo(string $codigo, string $verificador, ?string $dispositivo, ?string $versao): ?array
    {
        $dados = Cache::pull($this->chave($codigo));
        if (! $dados || ! hash_equals($dados['desafio'], self::desafioDe($verificador))) {
            return null;
        }
        $usuario = Usuario::find($dados['usuario_id']);
        if (! $usuario || ! $usuario->temAcesso()) {
            return null;
        }

        $token = Str::random(64);
        TokenAplicativo::create([
            'usuario_id' => $usuario->id,
            'token_hash' => hash('sha256', $token),
            'dispositivo' => Str::limit((string) $dispositivo, 115, '') ?: null,
            'versao_app' => Str::limit((string) $versao, 20, '') ?: null,
            'ultimo_uso_em' => now(),
        ]);

        return ['token' => $token, 'usuario' => $usuario];
    }

    /** Token válido: existe, não foi revogado, foi usado nos últimos dias e o usuário segue com acesso. */
    public function validar(?string $token): ?TokenAplicativo
    {
        if (! $token) {
            return null;
        }
        $registro = TokenAplicativo::with('usuario')->where('token_hash', hash('sha256', $token))->first();
        $limite = now()->subDays((int) config('oee.app.dias_sem_uso', 7));
        $validade = now()->subDays((int) config('oee.app.dias_validade_token', 30));
        if (! $registro || $registro->revogado_em || ! $registro->usuario?->temAcesso()
            || ($registro->ultimo_uso_em && $registro->ultimo_uso_em->lt($limite))
            || ($registro->criado_em && $registro->criado_em->lt($validade))) {
            return null;
        }
        if (! $registro->ultimo_uso_em || $registro->ultimo_uso_em->lt(now()->subMinutes(5))) {
            $registro->forceFill(['ultimo_uso_em' => now()])->save();
        }

        return $registro;
    }

    /** SHA-256 em base64url, sem "=" (igual ao code_challenge do PKCE). */
    public static function desafioDe(string $verificador): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verificador, true)), '+/', '-_'), '=');
    }

    private function chave(string $codigo): string
    {
        return 'app_login:'.hash('sha256', $codigo);
    }
}
