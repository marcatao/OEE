<?php

namespace App\Support;

use App\Models\CentroCusto;
use App\Models\Manutencao\AreaExecutante;
use App\Models\Manutencao\Mantenedor;
use App\Models\Manutencao\OrdemServico;
use App\Models\Maquina;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Quem vê, abre e cancela nas Ordens de serviço:
 * - ABRIR SS: quem tem o perfil Solicitante de Manutenção e/ou de Tecnologia e os administradores da área, só para as
 *   áreas executantes dessa unidade (Adm. Produção = Manutenção; Adm. Tecnologia = as duas);
 * - VER: o solicitante vê as SS/OS dele e as do seu centro de custo; o mantenedor vê as das áreas executantes que ele
 *   atende (sem áreas cadastradas: as da unidade do perfil) e as OS em que está atribuído; o administrador da área vê
 *   todas da unidade dele (Administrador de Produção = Manutenção; Administrador de Tecnologia = tudo);
 * - CANCELAR/REABRIR: só o administrador da área ou o próprio autor da solicitação (mesmo sendo mantenedor).
 */
final class AcessoOs
{
    /** @var array<string, true>|null códigos de centro de custo existentes (cache por requisição) */
    private static ?array $codigosCc = null;

    /** Unidades para as quais o usuário pode ABRIR SS. @return list<string> */
    public static function unidadesSolicitante(Usuario $u): array
    {
        if (! $u->temAcesso()) {
            return [];
        }
        $unidades = [];
        foreach ($u->perfisAcesso() as $perfil) {
            array_push($unidades, ...(Usuario::perfisDisponiveis()[$perfil]['solicita'] ?? []));
        }

        return array_values(array_unique($unidades));
    }

    public static function podeSolicitar(Usuario $u): bool
    {
        return self::unidadesSolicitante($u) !== [];
    }

    /** Unidades em que o usuário é administrador da área. @return list<string> */
    public static function unidadesAdmin(Usuario $u): array
    {
        if ($u->ehAdminTecnologia()) {
            return ['manutencao', 'tecnologia'];
        }

        return $u->ehAdminProducao() ? ['manutencao'] : [];
    }

    public static function ehAdminDa(Usuario $u, ?string $unidade): bool
    {
        return in_array((string) $unidade, self::unidadesAdmin($u), true);
    }

    /** Cancelar (SS ou OS) e reabrir OS: administrador da área ou o próprio autor da solicitação. */
    public static function podeCancelar(Usuario $u, object $registro): bool
    {
        return (int) $registro->solicitante_id === (int) $u->id || self::ehAdminDa($u, $registro->unidade);
    }

    public static function ehEquipe(Usuario $u): bool
    {
        return $u->pode('os_aprovar') || $u->pode('solicitacoes_manutencao') || $u->pode('os_almoxarifado');
    }

    public static function podeAprovar(Usuario $u): bool
    {
        return $u->pode('os_aprovar');
    }

    public static function podeExecutar(Usuario $u): bool
    {
        return $u->pode('solicitacoes_manutencao') || $u->pode('os_aprovar');
    }

    /** Unidades dos perfis de mantenedor do usuário. @return list<string> */
    private static function unidadesMantenedor(Usuario $u): array
    {
        return array_values(array_filter([
            $u->temPerfil('mantenedor_producao') ? 'manutencao' : null,
            $u->temPerfil('mantenedor_tecnologia') ? 'tecnologia' : null,
        ]));
    }

    /** Filtra uma consulta de SS ou OS pelo que o usuário pode ver. */
    public static function filtrar(Builder $consulta, Usuario $u): Builder
    {
        if ($u->ehAdminTecnologia()) {
            return $consulta;
        }
        $modelo = $consulta->getModel();
        $col = fn (string $c) => $modelo->qualifyColumn($c);
        $cc = self::centroCustoDo($u);
        $admin = self::unidadesAdmin($u);
        $mantenedor = self::mantenedorDo($u);
        $areas = $mantenedor ? $mantenedor->areas()->pluck('os_areas_executantes.id')->all() : [];
        $unidadesMant = self::unidadesMantenedor($u);
        // Almoxarifado/compras (dado por grupo) atende as OS das unidades dos perfis do usuário (sem perfil de unidade: todas).
        $almox = $u->pode('os_almoxarifado') ? ($u->unidades() ?: ['manutencao', 'tecnologia']) : [];

        return $consulta->where(function (Builder $w) use ($u, $col, $cc, $admin, $mantenedor, $areas, $unidadesMant, $almox, $modelo) {
            $w->where($col('solicitante_id'), $u->id);
            if ($cc !== null) {
                $w->orWhere($col('centro_custo'), $cc);
            }
            if ($admin) {
                $w->orWhereIn($col('unidade'), $admin);
            }
            if ($areas) {
                $w->orWhereIn($col('area_executante_id'), $areas);
            } elseif ($unidadesMant) {
                $w->orWhereIn($col('unidade'), $unidadesMant);
            }
            if ($mantenedor && $modelo instanceof OrdemServico) {
                $w->orWhereHas('mantenedores', fn ($m) => $m->where('os_mantenedores.id', $mantenedor->id));
            }
            if ($almox) {
                $w->orWhereIn($col('unidade'), $almox);
            }
        });
    }

    /** Mantenedor ligado ao login do usuário (para apontar as próprias horas). */
    public static function mantenedorDo(Usuario $u): ?Mantenedor
    {
        return Mantenedor::withoutGlobalScopes()->where('usuario_id', $u->id)->where('ativo', true)->first();
    }

    /**
     * Centro de custo do usuário: o "Centro de custo" que vem do Entra ID (local_escritorio), se for um código cadastrado
     * em Centros de custo (aceita "1001" ou "1001 - Injeção"). Sem correspondência: null.
     */
    public static function centroCustoDo(Usuario $u): ?string
    {
        $texto = trim((string) $u->local_escritorio);
        if ($texto === '') {
            return null;
        }
        self::$codigosCc ??= CentroCusto::withoutGlobalScope('unidade')->pluck('ctt_custo')
            ->mapWithKeys(fn ($c) => [trim((string) $c) => true])->all();
        foreach ([$texto, preg_split('/[\s\-–—]+/u', $texto)[0] ?? ''] as $candidato) {
            if ($candidato !== '' && isset(self::$codigosCc[$candidato])) {
                return $candidato;
            }
        }

        return null;
    }

    /** Código de centro de custo existe no cadastro? */
    public static function centroCustoExiste(?string $codigo): bool
    {
        $codigo = trim((string) $codigo);
        if ($codigo === '') {
            return false;
        }
        self::$codigosCc ??= CentroCusto::withoutGlobalScope('unidade')->pluck('ctt_custo')
            ->mapWithKeys(fn ($c) => [trim((string) $c) => true])->all();

        return isset(self::$codigosCc[$codigo]);
    }

    /** Para os testes: esquece o cache dos códigos. */
    public static function limparCache(): void
    {
        self::$codigosCc = null;
    }

    /** Máquinas ativas de todas as unidades (quem solicita pode pedir serviço para qualquer área). */
    public static function maquinas(): Collection
    {
        return Maquina::withoutGlobalScope('unidade')->where('ativo', true)->orderBy('codigo')->get(['id', 'codigo', 'nome', 'centro_custo', 'unidade']);
    }

    public static function areas(): Collection
    {
        return AreaExecutante::withoutGlobalScope('unidade')->where('ativo', true)->orderBy('nome')->get();
    }

    /** Áreas executantes para as quais o usuário pode abrir SS (da unidade dele como solicitante, ou "ambos"). */
    public static function areasParaSolicitar(Usuario $u): Collection
    {
        $unidades = self::unidadesSolicitante($u);

        return self::areas()->filter(fn ($a) => $a->unidade === 'ambos' ? $unidades !== [] : in_array($a->unidade, $unidades, true))->values();
    }

    public static function centrosCusto(): Collection
    {
        return CentroCusto::withoutGlobalScope('unidade')->selecionaveis()->orderBy('ctt_custo')->get(['ctt_custo', 'ctt_desc01']);
    }

    /** Mantenedores ativos das unidades que o usuário enxerga. */
    public static function mantenedores(): Collection
    {
        return Mantenedor::with(['usuario', 'areas'])->where('ativo', true)->orderBy('nome')->get();
    }
}
