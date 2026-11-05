<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Usuário do portal. O Entra ID só autentica; nome e e-mail vêm do login e o perfil é definido no portal.
 */
class Usuario extends Authenticatable
{
    public const PERFIS = ['operador', 'supervisor', 'admin'];

    const CREATED_AT = 'criado_em';

    const UPDATED_AT = 'atualizado_em';

    protected $table = 'usuarios';

    protected $fillable = ['entra_oid', 'entra_tenant_id', 'nome', 'email', 'perfil', 'ativo', 'ultimo_login_em'];

    // Sem senha nem "lembrar de mim": a autenticação é sempre pelo Entra ID.
    protected $rememberTokenName = '';

    protected $attributes = ['perfil' => 'operador', 'ativo' => true];

    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'ultimo_login_em' => 'datetime'];
    }

    public function temPerfil(string $minimo): bool
    {
        return array_search($this->perfil, self::PERFIS, true) >= array_search($minimo, self::PERFIS, true);
    }

    public function registrosLogin(): HasMany
    {
        return $this->hasMany(RegistroLogin::class);
    }
}
