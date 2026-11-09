<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Usuário do portal. Cadastrado no primeiro login pelo Entra ID (nome e e-mail vêm do login) e nasce SEM ACESSO.
 * O administrador define o perfil e os grupos; os grupos dão as permissões de tela.
 */
class Usuario extends Authenticatable
{
    public const PERFIS = [
        'sem_acesso' => 'Sem acesso',
        'operador' => 'Operador',
        'admin' => 'Administrador',
    ];

    const CREATED_AT = 'criado_em';

    const UPDATED_AT = 'atualizado_em';

    protected $table = 'usuarios';

    protected $fillable = [
        'entra_oid', 'entra_tenant_id', 'nome', 'email', 'perfil', 'ativo', 'ultimo_login_em',
        'setor', 'cargo', 'email_contato', 'celular', 'matricula',
    ];

    // Sem senha nem "lembrar de mim": a autenticação é sempre pelo Entra ID.
    protected $rememberTokenName = '';

    protected $attributes = ['perfil' => 'sem_acesso', 'ativo' => true];

    private ?array $permissoesCache = null;

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'ultimo_login_em' => 'datetime',
            'foto_atualizada_em' => 'datetime',
            'perfil_entra_em' => 'datetime',
        ];
    }

    public function grupos(): BelongsToMany
    {
        return $this->belongsToMany(Grupo::class, 'grupo_usuario');
    }

    public function registrosLogin(): HasMany
    {
        return $this->hasMany(RegistroLogin::class);
    }

    public function ehAdmin(): bool
    {
        return $this->perfil === 'admin';
    }

    /** Liberado por um administrador (perfil diferente de "sem acesso") e ativo. */
    public function temAcesso(): bool
    {
        return $this->ativo && $this->perfil !== 'sem_acesso';
    }

    /** Pode abrir a tela? Admin pode tudo; os demais, o que os grupos ativos deles permitem. */
    public function pode(string $permissao): bool
    {
        if (! $this->temAcesso()) {
            return false;
        }

        return $this->ehAdmin() || in_array($permissao, $this->permissoes(), true);
    }

    /** @return list<string> permissões vindas dos grupos ativos */
    public function permissoes(): array
    {
        return $this->permissoesCache ??= DB::table('grupo_permissoes')
            ->join('grupo_usuario', 'grupo_usuario.grupo_id', '=', 'grupo_permissoes.grupo_id')
            ->join('grupos', 'grupos.id', '=', 'grupo_permissoes.grupo_id')
            ->where('grupo_usuario.usuario_id', $this->id)
            ->where('grupos.ativo', true)
            ->distinct()
            ->pluck('grupo_permissoes.permissao')
            ->all();
    }

    public function rotuloPerfil(): string
    {
        return self::PERFIS[$this->perfil] ?? $this->perfil;
    }

    /** Iniciais do nome (ex.: "Marcato Silva" → "MS") para quando não há foto. */
    public function iniciais(): string
    {
        $partes = preg_split('/\s+/', trim((string) ($this->nome ?: $this->email))) ?: [];
        $primeira = $partes[0] ?? '';
        $ultima = count($partes) > 1 ? end($partes) : '';

        return mb_strtoupper(mb_substr($primeira, 0, 1).mb_substr((string) $ultima, 0, 1)) ?: '?';
    }

    /**
     * Tem foto e o arquivo existe neste servidor? (Ao trocar de servidor com o mesmo banco, o caminho fica gravado mas o
     * arquivo pode ter ficado na pasta storage do servidor antigo.)
     */
    public function temFoto(): bool
    {
        return (bool) $this->foto && Storage::disk('local')->exists($this->foto);
    }

    /** Endereço da foto de perfil (servida pelo portal, sem link público para a pasta storage). */
    public function urlFoto(): ?string
    {
        if (! $this->temFoto()) {
            return null;
        }

        return route('usuarios.foto', ['usuario' => $this->id, 'v' => $this->foto_atualizada_em?->timestamp]);
    }

    public function primeiroNome(): string
    {
        return strtok(trim((string) ($this->nome ?: $this->email)), ' @') ?: '';
    }
}
