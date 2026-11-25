<?php

namespace App\Services\Manutencao;

use App\Models\Manutencao\ApontamentoHoras;
use App\Models\Manutencao\Mantenedor;
use App\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Relatórios de horas da manutenção (planilha do processo):
 * - apontamentos(): linha a linha (intranet / planilha geral da contabilidade);
 * - setores(): horas por setor (centro de custo da OS) no mês × média mensal dos meses anteriores — diferença
 *   ≥ LIMITE_INCOMUM é "Incomum" (pede justificativa), abaixo é "Aceitável";
 * - aproveitamento(): horas apontadas × horas disponíveis (jornada) por mantenedor — meta > META_APROVEITAMENTO.
 * Períodos no fuso local; o banco guarda UTC.
 */
final class RelatorioHorasManutencao
{
    public const LIMITE_INCOMUM = 0.20;

    public const META_APROVEITAMENTO = 0.90;

    public const MESES_MEDIA = 6;

    /** Apontamentos encerrados com início no período, das OS que o usuário enxerga. */
    public function apontamentos(Usuario $u, CarbonImmutable $de, CarbonImmutable $ate, array $filtros = []): Builder
    {
        return ApontamentoHoras::query()
            ->whereNotNull('fim')
            ->where('inicio', '>=', $de->utc())->where('inicio', '<', $ate->utc())
            ->whereHas('ordemServico', function ($q) use ($u, $filtros) {
                $q->visiveisPara($u);
                if (! empty($filtros['tipo'])) {
                    $q->where('tipo', $filtros['tipo']);
                }
                if (! empty($filtros['maquina'])) {
                    $q->where('maquina_id', $filtros['maquina']);
                }
                if (! empty($filtros['centro_custo'])) {
                    $q->where('centro_custo', $filtros['centro_custo']);
                }
                if (! empty($filtros['unidade'])) {
                    $q->where('unidade', $filtros['unidade']);
                }
                if (! empty($filtros['area'])) {
                    $q->where('area_executante_id', $filtros['area']);
                }
            })
            ->when(! empty($filtros['mantenedor']), fn ($q) => $q->where('mantenedor_id', $filtros['mantenedor']))
            ->with(['ordemServico.maquina' => fn ($q) => $q->withoutGlobalScopes(), 'ordemServico.ferramenta', 'ordemServico.itemTi',
                'ordemServico.areaExecutante' => fn ($q) => $q->withoutGlobalScopes(), 'mantenedor' => fn ($q) => $q->withoutGlobalScopes(), 'criadoPor'])
            ->orderBy('inicio');
    }

    /** Minutos por setor (unidade + centro de custo da OS) no período. @return Collection<string, int> chave "unidade|cc" */
    public function minutosPorSetor(Usuario $u, CarbonImmutable $de, CarbonImmutable $ate): Collection
    {
        $visiveis = $u->unidadesVisiveis();

        return DB::table('os_apontamentos_horas as a')
            ->join('ordens_servico as o', 'o.id', '=', 'a.ordem_servico_id')
            ->whereNotNull('a.fim')
            ->where('a.inicio', '>=', $de->utc())->where('a.inicio', '<', $ate->utc())
            ->when($visiveis !== null, fn ($q) => $q->whereIn('o.unidade', $visiveis))
            ->groupBy('o.unidade', 'o.centro_custo')
            ->selectRaw('o.unidade, o.centro_custo, SUM(COALESCE(a.minutos_normais, 0) + COALESCE(a.minutos_extras, 0)) as minutos')
            ->get()
            ->mapWithKeys(fn ($l) => [$l->unidade.'|'.trim((string) $l->centro_custo) => (int) $l->minutos]);
    }

    /**
     * Horas por setor no mês × média dos meses anteriores.
     *
     * @return list<array{unidade: string, centro_custo: string, minutos: int, media: float, diferenca: ?float, incomum: bool}>
     */
    public function setores(Usuario $u, CarbonImmutable $mes): array
    {
        $inicio = $mes->startOfMonth();
        $atual = $this->minutosPorSetor($u, $inicio, $inicio->addMonth());
        $anteriores = [];
        for ($i = 1; $i <= self::MESES_MEDIA; $i++) {
            $anteriores[] = $this->minutosPorSetor($u, $inicio->subMonths($i), $inicio->subMonths($i - 1));
        }
        $chaves = $atual->keys()->merge(collect($anteriores)->flatMap(fn ($c) => $c->keys()))->unique()->sort()->values();

        $linhas = [];
        foreach ($chaves as $chave) {
            [$unidade, $cc] = explode('|', $chave, 2);
            $minutos = (int) ($atual[$chave] ?? 0);
            $media = array_sum(array_map(fn ($c) => (int) ($c[$chave] ?? 0), $anteriores)) / self::MESES_MEDIA;
            $diferenca = $media > 0 ? ($minutos - $media) / $media : null;
            $linhas[] = [
                'unidade' => $unidade, 'centro_custo' => $cc, 'minutos' => $minutos, 'media' => $media, 'diferenca' => $diferenca,
                'incomum' => $diferenca === null ? $minutos > 0 : abs($diferenca) >= self::LIMITE_INCOMUM,
            ];
        }

        return $linhas;
    }

    /**
     * Apontado × disponível por mantenedor no período (até hoje, se o período ainda não acabou).
     * Com $filtros (os do relatório de horas): o apontado segue os mesmos filtros da lista; unidade, área executante e
     * mantenedor também limitam os mantenedores; com tipo, máquina ou centro de custo, só aparece quem apontou neles.
     *
     * @return list<array{mantenedor: Mantenedor, apontado: int, disponivel: int, aproveitamento: ?float}>
     */
    public function aproveitamento(Usuario $u, CarbonImmutable $de, CarbonImmutable $ate, ?array $filtros = null): array
    {
        $hoje = CarbonImmutable::now(HorasManutencao::fuso())->endOfDay();
        $fimDisponivel = $ate->subSecond() < $hoje ? $ate->subSecond() : $hoje;
        $consulta = $filtros === null
            ? ApontamentoHoras::whereNotNull('fim')->where('inicio', '>=', $de->utc())->where('inicio', '<', $ate->utc())
            : $this->apontamentos($u, $de, $ate, $filtros)->setEagerLoads([])->reorder();
        $apontado = $consulta->groupBy('mantenedor_id')
            ->selectRaw('mantenedor_id, SUM(COALESCE(minutos_normais, 0) + COALESCE(minutos_extras, 0)) as minutos')
            ->pluck('minutos', 'mantenedor_id');

        $filtros ??= [];
        $soQuemApontou = ! empty($filtros['tipo']) || ! empty($filtros['maquina']) || ! empty($filtros['centro_custo']);
        $mantenedores = Mantenedor::query()
            ->when(! empty($filtros['mantenedor']), fn ($q) => $q->whereKey($filtros['mantenedor']))
            ->when(! empty($filtros['unidade']), fn ($q) => $q->whereIn('unidade', [$filtros['unidade'], 'ambos']))
            ->when(! empty($filtros['area']), fn ($q) => $q->where(fn ($q) => $q->where('area_executante_id', $filtros['area'])
                ->orWhereHas('areas', fn ($a) => $a->whereKey($filtros['area']))))
            ->orderBy('nome')->get();

        $linhas = [];
        foreach ($mantenedores as $m) {
            $minutos = (int) ($apontado[$m->id] ?? 0);
            if ($minutos === 0 && (! $m->ativo || $soQuemApontou)) {
                continue;
            }
            $disponivel = $fimDisponivel >= $de ? HorasManutencao::de($m)->minutosDisponiveis($de, $fimDisponivel) : 0;
            $linhas[] = ['mantenedor' => $m, 'apontado' => $minutos, 'disponivel' => $disponivel, 'aproveitamento' => $disponivel > 0 ? $minutos / $disponivel : null];
        }

        return $linhas;
    }
}
