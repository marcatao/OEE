<?php

namespace App\Models;

use App\Support\Unidades;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Usuário do portal. Cadastrado no primeiro login pelo Entra ID (nome e e-mail vêm do login) e nasce SEM ACESSO.
 * O Administrador de Tecnologia define os PERFIS (vários por usuário, tabela usuario_perfis; config oee.perfis_acesso)
 * e os grupos. O perfil dá as telas e o ambiente (unidade: manutenção e/ou tecnologia); os grupos somam telas.
 * Usuário ainda sem perfis gravados segue a coluna antiga "perfil": admin = todos os perfis, operador = operador.
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
        'departamento', 'local_escritorio', 'gerente_nome', 'gerente_email',
    ];

    // Sem senha nem "lembrar de mim": a autenticação é sempre pelo Entra ID.
    protected $rememberTokenName = '';

    protected $attributes = ['perfil' => 'sem_acesso', 'ativo' => true];

    private ?array $permissoesCache = null;

    private ?array $perfisCache = null;

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

    /** @return array<string, array{rotulo: string, descricao: string, unidades: list<string>, permissoes: list<string>}> */
    public static function perfisDisponiveis(): array
    {
        return config('oee.perfis_acesso', []);
    }

    /**
     * Perfis de acesso do usuário. Sem perfis gravados em usuario_perfis, segue a coluna antiga "perfil"
     * (admin = todos os perfis, operador = operador, sem_acesso = nenhum): os cadastros antigos não perdem nada.
     *
     * @return list<string>
     */
    public function perfisAcesso(): array
    {
        if ($this->perfisCache !== null) {
            return $this->perfisCache;
        }
        $validos = array_keys(self::perfisDisponiveis());
        $gravados = $this->exists ? DB::table('usuario_perfis')->where('usuario_id', $this->id)->pluck('perfil')->all() : [];
        if (! $gravados) {
            $gravados = match ($this->perfil) {
                'admin' => $validos,
                'operador' => ['operador'],
                default => [],
            };
        }

        return $this->perfisCache = array_values(array_intersect($validos, $gravados));
    }

    /** Grava os perfis (troca os que tinha) e ajusta a coluna antiga "perfil" (sem_acesso, operador ou admin). */
    public function definirPerfis(array $perfis, ?Usuario $por = null): void
    {
        $perfis = array_values(array_intersect(array_keys(self::perfisDisponiveis()), $perfis));
        DB::transaction(function () use ($perfis, $por) {
            DB::table('usuario_perfis')->where('usuario_id', $this->id)->whereNotIn('perfil', $perfis ?: [''])->delete();
            $atuais = DB::table('usuario_perfis')->where('usuario_id', $this->id)->pluck('perfil')->all();
            foreach (array_diff($perfis, $atuais) as $perfil) {
                DB::table('usuario_perfis')->insert(['usuario_id' => $this->id, 'perfil' => $perfil,
                    'criado_em' => now(), 'criado_por_id' => $por?->id]);
            }
            $this->forceFill(['perfil' => match (true) {
                $perfis === [] => 'sem_acesso',
                (bool) array_intersect($perfis, array_values(config('oee.admins_area'))) => 'admin',
                default => 'operador',
            }])->save();
        });
        $this->perfisCache = null;
        $this->permissoesCache = null;
    }

    public function temPerfil(string ...$perfis): bool
    {
        return (bool) array_intersect($perfis, $this->perfisAcesso());
    }

    public function ehAdminTecnologia(): bool
    {
        return $this->temPerfil('admin_tecnologia');
    }

    public function ehAdminProducao(): bool
    {
        return $this->temPerfil('admin_producao');
    }

    public function ehAdminFerramentaria(): bool
    {
        return $this->temPerfil('admin_ferramentaria');
    }

    /** Pode abrir os cadastros (máquinas, legendas, IoT...): Administrador de Produção, de Tecnologia ou de Ferramentaria. */
    public function ehAdmin(): bool
    {
        return $this->temPerfil(...array_values(config('oee.admins_area')));
    }

    /** Áreas em que o usuário tem o perfil de administrador (sem o "pode tudo" do Adm. de Tecnologia). @return list<string> */
    public function areasAdministradas(): array
    {
        return array_keys(array_filter(config('oee.admins_area'), fn ($perfil) => $this->temPerfil($perfil)));
    }

    /** Aba Segurança (usuários, grupos, histórico de acessos, banco de dados): só o Administrador de Tecnologia. */
    public function podeSeguranca(): bool
    {
        return $this->temAcesso() && $this->ehAdminTecnologia();
    }

    /** Ativo e com pelo menos um perfil. */
    public function temAcesso(): bool
    {
        return $this->ativo && $this->perfisAcesso() !== [];
    }

    /** Áreas que o usuário enxerga (manutencao, tecnologia, ferramentaria). @return list<string> */
    public function unidades(): array
    {
        $unidades = [];
        foreach ($this->perfisAcesso() as $perfil) {
            array_push($unidades, ...(self::perfisDisponiveis()[$perfil]['unidades'] ?? []));
        }

        return array_values(array_unique($unidades));
    }

    /**
     * Filtro dos cadastros por unidade: null = vê tudo (todas as áreas); senão as unidades visíveis (as dele e "ambos",
     * que é o cadastro de todas as áreas).
     *
     * @return list<string>|null
     */
    public function unidadesVisiveis(): ?array
    {
        $unidades = $this->temAcesso() ? $this->unidades() : [];

        return array_diff(Unidades::areas(), $unidades) === [] ? null : [...$unidades, 'ambos'];
    }

    /**
     * Unidades que o usuário pode escolher num cadastro: cada área só com o administrador dela (Produção = Manutenção,
     * Tecnologia, Ferramentaria); "Todas" (valor ambos) só para quem administra mais de uma área.
     *
     * @return list<string>
     */
    public function unidadesParaCadastro(): array
    {
        $areas = $this->areasAdministradas();

        return count($areas) > 1 ? [...$areas, 'ambos'] : $areas;
    }

    /**
     * Pode abrir a tela? Administrador de Tecnologia pode tudo; os demais, o que os perfis e os grupos ativos deles
     * permitem.
     */
    public function pode(string $permissao): bool
    {
        if (! $this->temAcesso()) {
            return false;
        }
        if ($this->ehAdminTecnologia()) {
            return true;
        }
        foreach ($this->perfisAcesso() as $perfil) {
            $doPerfil = self::perfisDisponiveis()[$perfil]['permissoes'] ?? [];
            if (in_array('*', $doPerfil, true) || in_array($permissao, $doPerfil, true)) {
                return true;
            }
        }

        return in_array($permissao, $this->permissoes(), true);
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

    /** Rótulo curto para o topo: o perfil principal e quantos mais (ex.: "Administrador de Tecnologia +4"). */
    public function rotuloPerfilCurto(): string
    {
        $perfis = $this->perfisAcesso();
        if (! $perfis) {
            return 'Sem acesso';
        }
        $ordem = ['admin_tecnologia', 'admin_producao', 'mantenedor_tecnologia', 'mantenedor_producao', 'operador'];
        $principal = collect($ordem)->first(fn ($p) => in_array($p, $perfis, true)) ?? $perfis[0];

        return self::perfisDisponiveis()[$principal]['rotulo'].(count($perfis) > 1 ? ' +'.(count($perfis) - 1) : '');
    }

    /** "Operador, Mantenedor de Produção" (ou "Sem acesso"). */
    public function rotuloPerfil(): string
    {
        $rotulos = array_map(fn ($p) => self::perfisDisponiveis()[$p]['rotulo'], $this->perfisAcesso());

        return $rotulos ? implode(', ', $rotulos) : 'Sem acesso';
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
