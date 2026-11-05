<?php

namespace App\Services;

use App\Models\RegistroLogin;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Grava no banco o usuário que entrou (nome e e-mail vindos do Entra ID) e cada tentativa de login.
 */
class RegistroDeLogin
{
    public function __construct(private Request $request) {}

    /**
     * Cria ou atualiza o usuário. Devolve null (e grava a recusa) se ele estiver desativado no portal.
     */
    public function sucesso(IdentidadeEntra $identidade): ?Usuario
    {
        $usuario = Usuario::firstOrNew(['entra_oid' => $identidade->oid]);
        $usuario->fill([
            'entra_tenant_id' => $identidade->tenantId,
            'nome' => $identidade->nome,
            'email' => $identidade->email,
        ]);

        $admins = config('oee.admins');
        if ($identidade->email && in_array(Str::lower($identidade->email), $admins, true)) {
            $usuario->perfil = 'admin';
        }

        if ($usuario->exists && ! $usuario->ativo) {
            $usuario->save();
            $this->falha($identidade->email, 'usuário desativado no portal', $usuario);

            return null;
        }

        $usuario->ultimo_login_em = now();
        $usuario->save();
        $this->gravar($usuario, $identidade->email, true, null);

        return $usuario;
    }

    public function falha(?string $email, string $motivo, ?Usuario $usuario = null): void
    {
        $this->gravar($usuario, $email, false, Str::limit($motivo, 197));
    }

    private function gravar(?Usuario $usuario, ?string $email, bool $sucesso, ?string $motivo): void
    {
        RegistroLogin::create([
            'usuario_id' => $usuario?->id,
            'email' => $email,
            'data_hora' => now(),
            'sucesso' => $sucesso,
            'motivo' => $motivo,
            'ip' => $this->request->ip(),
            'navegador' => Str::limit((string) $this->request->userAgent(), 397),
        ]);
    }
}
