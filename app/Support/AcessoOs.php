<?php

namespace App\Support;

use App\Models\CentroCusto;
use App\Models\Ferramenta;
use App\Models\InventarioTi\ItemTi;
use App\Models\Manutencao\AreaExecutante;
use App\Models\Manutencao\Mantenedor;
use App\Models\Manutencao\OrdemServico;
use App\Models\Maquina;
use App\Models\Usuario;
use App\Services\Manutencao\SsAutomatica;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Quem vê, abre e cancela nas Ordens de serviço:
 * - UNIDADES: cada um só enxerga as unidades HABILITADAS nos perfis dele (Usuario::unidades()), em tudo — listas,
 *   filtros, cadastros e telas. O Administrador de Tecnologia também (só a Tecnologia, se não tiver outros perfis);
 * - ABRIR SS: quem tem o perfil Solicitante da unidade e os administradores dela, só para as áreas executantes dessa
 *   unidade;
 * - VER (dentro das unidades habilitadas): o solicitante vê as SS/OS dele e as do seu centro de custo; o mantenedor vê
 *   as das áreas executantes que ele atende (sem áreas cadastradas: as da unidade do perfil) e as OS em que está
 *   atribuído; o administrador da área vê todas da unidade dele;
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
        return $u->areasAdministradas();
    }

    /**
     * Unidades HABILITADAS para o usuário nas Ordens de serviço: as dos perfis dele (operador, mantenedor,
     * administrador) e as em que é Solicitante. Sem nenhum perfil de unidade: nenhuma.
     *
     * @return list<string>
     */
    public static function unidadesHabilitadas(Usuario $u): array
    {
        // Sem perfil de unidade: nenhuma (quem não tem perfil não vê nada).
        return array_values(array_intersect(Unidades::areas(), [...$u->unidades(), ...self::unidadesSolicitante($u)]));
    }

    /**
     * Filtro de unidade das listas (Mantenedores, Áreas executantes, Preventivas): só as unidades habilitadas no login;
     * unidade pedida fora delas é ignorada.
     *
     * @return array{0: list<string>, 1: string} unidades permitidas e a escolhida ('' = todas as minhas)
     */
    public static function filtroUnidade(Usuario $u, ?string $pedida): array
    {
        $unidades = self::unidadesHabilitadas($u);

        return [$unidades, in_array((string) $pedida, $unidades, true) ? (string) $pedida : ''];
    }

    /** Restringe um cadastro com "unidade" às unidades habilitadas do usuário logado (e às de "Todas"). */
    private static function daUnidadeDoUsuario(Builder $consulta): Builder
    {
        $u = Auth::user();
        if (! $u instanceof Usuario) {
            return $consulta;
        }

        return $consulta->whereIn($consulta->getModel()->qualifyColumn('unidade'), [...self::unidadesHabilitadas($u), 'ambos']);
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

    /** Pode aprovar SS (e editar/distribuir OS)? Com o registro, só se a área executante dele for uma das que atende. */
    public static function podeAprovar(Usuario $u, ?object $registro = null): bool
    {
        return $u->pode('os_aprovar') && ($registro === null || self::atendeArea($u, $registro));
    }

    /** Pode executar (apontar horas, materiais, checklist, concluir)? Com a OS: se atende a área dela ou está atribuído. */
    public static function podeExecutar(Usuario $u, ?object $registro = null): bool
    {
        if (! ($u->pode('solicitacoes_manutencao') || $u->pode('os_aprovar'))) {
            return false;
        }
        if ($registro === null || self::atendeArea($u, $registro)) {
            return true;
        }
        $m = self::mantenedorDo($u);

        return $m !== null && $registro instanceof OrdemServico
            && ((int) $registro->responsavel_id === $m->id || $registro->mantenedores()->where('os_mantenedores.id', $m->id)->exists());
    }

    /**
     * A área executante da SS/OS é uma das que o usuário atende? Administrador da área: todas da unidade. Mantenedor:
     * as áreas executantes do cadastro dele (Ordens de serviço › Mantenedores). Mantenedor ainda sem áreas cadastradas
     * (ou quem aprova por grupo): as da unidade do perfil.
     */
    public static function atendeArea(Usuario $u, object $registro): bool
    {
        if (self::ehAdminDa($u, $registro->unidade)) {
            return true;
        }
        $areas = self::areasDo($u);
        if ($areas !== []) {
            return in_array((int) $registro->area_executante_id, $areas, true);
        }

        return in_array($registro->unidade, [...self::unidadesMantenedor($u), ...$u->unidades()], true);
    }

    /** Áreas executantes do cadastro de mantenedor do usuário (vazio = sem cadastro ou sem áreas). @return list<int> */
    public static function areasDo(Usuario $u): array
    {
        $m = self::mantenedorDo($u);

        return $m ? $m->areas()->pluck('os_areas_executantes.id')->map(fn ($i) => (int) $i)->all() : [];
    }

    /** Mantenedores que atendem a área executante da SS/OS (os sem áreas cadastradas entram pela unidade). */
    public static function mantenedoresDaArea(object $registro): Collection
    {
        return self::mantenedores()->filter(fn ($m) => $m->areas->isNotEmpty()
            ? $m->areas->contains('id', (int) $registro->area_executante_id)
            : in_array($m->unidade, [$registro->unidade, 'ambos'], true))->values();
    }

    /** Unidades dos perfis de mantenedor do usuário. @return list<string> */
    private static function unidadesMantenedor(Usuario $u): array
    {
        return array_keys(array_filter(config('oee.mantenedores_area'), fn ($perfil) => $u->temPerfil($perfil)));
    }

    /** Filtra uma consulta de SS ou OS pelo que o usuário pode ver. */
    public static function filtrar(Builder $consulta, Usuario $u): Builder
    {
        $modelo = $consulta->getModel();
        // Nunca fora das unidades habilitadas nos perfis (nem pelo centro de custo, nem como solicitante).
        $habilitadas = self::unidadesHabilitadas($u);
        if (array_diff(Unidades::areas(), $habilitadas) !== []) {
            $consulta->whereIn($modelo->qualifyColumn('unidade'), $habilitadas);
        }
        $col = fn (string $c) => $modelo->qualifyColumn($c);
        $cc = self::centroCustoDo($u);
        $admin = self::unidadesAdmin($u);
        $mantenedor = self::mantenedorDo($u);
        $areas = $mantenedor ? $mantenedor->areas()->pluck('os_areas_executantes.id')->all() : [];
        $unidadesMant = self::unidadesMantenedor($u);
        // Almoxarifado/compras (dado por grupo) atende as OS das unidades dos perfis do usuário (sem perfil de unidade: todas).
        $almox = $u->pode('os_almoxarifado') ? ($u->unidades() ?: Unidades::areas()) : [];

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

    /**
     * Áreas (unidades) do painel: todas as unidades HABILITADAS no login (perfis de operador, solicitante, mantenedor ou
     * administrador de cada uma). Sem perfil de unidade (acesso só por grupos): todas. @return list<string>
     */
    public static function unidadesPainel(Usuario $u): array
    {
        return array_values(array_intersect(Unidades::areas(), self::unidadesHabilitadas($u)));
    }

    /**
     * Áreas para as quais o usuário pede material: as dos perfis de mantenedor e de administrador. @return list<string>
     */
    public static function unidadesMaterial(Usuario $u): array
    {
        return array_values(array_intersect(Unidades::areas(), [...self::unidadesAdmin($u), ...self::unidadesMantenedor($u)]));
    }

    /** OS fechada (concluída, encerrada ou cancelada) não se altera: nada de editar, conversar, apontar ou requisitar. */
    public static function exigirAberta(OrdemServico $os): void
    {
        if (! $os->estaAberta()) {
            throw ValidationException::withMessages(['os' => 'Esta OS está '.mb_strtolower(OrdemServico::STATUS[$os->status] ?? $os->status)
                .($os->status === 'encerrada' ? ' (avaliada pelo solicitante) e não pode mais ser alterada.' : '. Para alterar, conversar ou apontar, ela precisa ser reaberta.')]);
        }
    }

    /**
     * Só o próprio solicitante avalia (nem o administrador, a não ser que ele seja o solicitante). OS automática aberta
     * pelo IoT (o solicitante é o usuário técnico, que não entra no portal): avalia o administrador da unidade.
     */
    public static function podeAvaliar(Usuario $u, OrdemServico $os): bool
    {
        if ($os->status !== 'concluida') {
            return false;
        }
        if ($os->automatica && SsAutomatica::solicitanteEhIot($os->solicitante_id)) {
            return self::ehAdminDa($u, $os->unidade);
        }

        return $os->solicitante_id !== null && (int) $os->solicitante_id === (int) $u->id;
    }

    /** OS concluídas que o usuário (solicitante) ainda precisa avaliar. */
    public static function pendentesDeAvaliacao(Usuario $u): Collection
    {
        return OrdemServico::where('solicitante_id', $u->id)->where('status', 'concluida')->orderBy('concluida_em')->get(['id', 'titulo', 'descricao', 'concluida_em']);
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

    /** Máquinas ativas das unidades do usuário logado (e as de "Todas"). */
    public static function maquinas(): Collection
    {
        return self::daUnidadeDoUsuario(Maquina::withoutGlobalScope('unidade'))->where('ativo', true)->orderBy('codigo')->get(['id', 'codigo', 'nome', 'centro_custo', 'unidade']);
    }

    /** Ferramentas/moldes (SH4) válidas das unidades do usuário logado que abrem chamado (`oee.os_unidades_ferramenta`) e as de "Todas". */
    public static function ferramentas(): Collection
    {
        return self::daUnidadeDoUsuario(Ferramenta::withoutGlobalScope('unidade'))->validas()
            ->whereIn('unidade', [...config('oee.os_unidades_ferramenta'), 'ambos'])
            ->orderBy('h4_codigo')->get(['id', 'h4_codigo', 'h4_descri', 'unidade']);
    }

    /**
     * Itens ativos do inventário de TI das unidades do usuário logado que abrem chamado para item de TI
     * (`oee.os_unidades_item_ti`) e os de "Todas"; os que estão com o próprio usuário vêm primeiro.
     */
    public static function itensTi(): Collection
    {
        $u = Auth::user();

        return self::daUnidadeDoUsuario(ItemTi::withoutGlobalScopes())->ativos()
            ->whereIn('unidade', [...config('oee.os_unidades_item_ti'), 'ambos'])
            ->orderBy('descricao')->orderBy('id')
            ->get(['id', 'patrimonio', 'descricao', 'categoria', 'linha_numero', 'unidade', 'centro_custo', 'responsavel_id'])
            ->sortBy(fn ($i) => $u && (int) $i->responsavel_id === (int) $u->id ? 0 : 1)->values();
    }

    /** A máquina é da área executante? Mesma unidade, ou uma das duas cadastrada para "Todas" (ambos). */
    public static function maquinaDaArea(Maquina $maquina, ?AreaExecutante $area): bool
    {
        return $area === null || in_array('ambos', [$area->unidade, $maquina->unidade], true) || $area->unidade === $maquina->unidade;
    }

    /** Máquinas ativas das unidades destas áreas executantes (e as de "Todas"). */
    public static function maquinasDasAreas(Collection $areas): Collection
    {
        $unidades = $areas->pluck('unidade')->unique()->values()->all();
        if (in_array('ambos', $unidades, true)) {
            return self::maquinas();
        }

        return self::maquinas()->filter(fn ($m) => in_array($m->unidade, [...$unidades, 'ambos'], true))->values();
    }

    /** Áreas executantes ativas das unidades do usuário logado (e as de "Todas"). */
    public static function areas(): Collection
    {
        return self::daUnidadeDoUsuario(AreaExecutante::withoutGlobalScope('unidade'))->where('ativo', true)->orderBy('nome')->get();
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
