<?php

namespace App\Services\FaltaProdutos;

use App\Models\ApontamentoEstado;
use App\Models\FaltaProduto;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use App\Models\ProdutoProtheus;
use App\Services\CustoOrdemProducao;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Produção › Falta de produtos: as faltas da ZB5 cruzadas com a produção do portal.
 *
 * Para cada produto em falta (pendente ou atendida parcialmente), a situação da PRODUÇÃO, da mais adiantada para a
 * menos:
 * - em_producao: alguma OP aberta do produto está num apontamento real EM ABERTO do tipo "produzindo";
 * - direcionada: alguma OP aberta do produto é a OP atual de uma máquina (mas a máquina não está produzindo);
 * - com_op: há OP aberta (não fechada nem excluída no Protheus), sem máquina;
 * - sem_op: nenhuma OP aberta do produto.
 * Falta concluída ou cancelada = "encerrada" (a produção não importa mais).
 * Máquinas: só as das unidades que o usuário vê (escopo de unidade do cadastro).
 *
 * Painéis (sobre as faltas do filtro): faltas, produtos, saldo e valor dos pedidos em aberto, tempo médio de
 * atendimento (solicitação → atendimento), idade das pendentes, situação da produção e produtos que mais faltam. O
 * gráfico mês a mês (faltantes × atendidos) usa o período e a busca, mas NÃO o status (senão, com o padrão "em aberto",
 * nunca haveria atendidos); sem período, os últimos 12 meses.
 */
class FaltaProdutos
{
    public const PRODUCAO = [
        'em_producao' => 'Em produção',
        'direcionada' => 'Direcionada a máquina',
        'com_op' => 'Com OP, sem máquina',
        'sem_op' => 'Sem OP',
    ];

    public const POR_PAGINA = 50;

    /** Faixas de idade das faltas em aberto (minutos até => rótulo). */
    public const IDADES = [24 * 60 => 'Até 1 dia', 3 * 24 * 60 => '1 a 3 dias', 7 * 24 * 60 => '3 a 7 dias', PHP_INT_MAX => 'Mais de 7 dias'];

    public function __construct(private CustoOrdemProducao $custo) {}

    /**
     * @return array{faltas: LengthAwarePaginator, producao: array, produtos: Collection, painel: array, meses: list<array>}
     */
    public function montar(FiltroFaltaProdutos $filtro, int $pagina = 1, ?string $caminho = null): array
    {
        $faltas = $this->consulta($filtro)
            // Status em branco no Protheus é tratado como Pendente (FaltaProduto::status): entra junto com o P.
            ->whereIn('zb5_status', in_array('P', $filtro->statusEfetivos(), true) ? [...$filtro->statusEfetivos(), '', ' '] : $filtro->statusEfetivos())
            ->orderByRaw('CASE WHEN solicitado_em IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('solicitado_em')->orderByDesc('r_e_c_n_o_')
            ->get();

        $producao = $this->producao($faltas->filter(fn (FaltaProduto $f) => $f->emAberto())->map->produto()->unique()->values()->all());
        $situacao = fn (FaltaProduto $f) => $f->emAberto() ? ($producao[$f->produto()]['situacao'] ?? 'sem_op') : 'encerrada';
        if ($filtro->producao) {
            $faltas = $faltas->filter(fn (FaltaProduto $f) => in_array($situacao($f), $filtro->producao, true))->values();
        }
        $faltas->each(fn (FaltaProduto $f) => $f->setAttribute('situacao_producao', $situacao($f)));

        $paginadas = new LengthAwarePaginator($faltas->forPage($pagina, self::POR_PAGINA)->values(), $faltas->count(),
            self::POR_PAGINA, $pagina, ['path' => $caminho ?? request()->url(), 'query' => $filtro->parametros()]);

        return [
            'faltas' => $paginadas,
            'producao' => $producao,
            'produtos' => $this->custo->produtos($faltas->map->produto()),
            'painel' => $this->painel($faltas),
            'meses' => $this->meses($filtro),
        ];
    }

    /** Faltas válidas do período e da busca (sem status). */
    private function consulta(FiltroFaltaProdutos $filtro): Builder
    {
        $busca = $filtro->busca;

        return FaltaProduto::validas()
            ->when($filtro->inicioUtc(), fn ($q, $d) => $q->where('solicitado_em', '>=', $d))
            ->when($filtro->fimUtc(), fn ($q, $d) => $q->where('solicitado_em', '<', $d))
            ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w->where('zb5_produt', 'like', "%{$busca}%")
                ->orWhere('zb5_doc', 'like', "%{$busca}%")
                ->orWhere('zb5_pedido', 'like', "%{$busca}%")
                ->orWhere('zb5_solici', 'like', "%{$busca}%")
                ->orWhereIn('zb5_produt', ProdutoProtheus::where('b1_desc', 'like', "%{$busca}%")->select('b1_cod'))));
    }

    /**
     * Situação da produção de cada produto: OPs abertas, máquinas com essas OPs e as que estão produzindo agora.
     *
     * @param  list<string>  $produtos
     * @return array<string, array{situacao: string, ops: Collection, direcionadas: Collection, produzindo: Collection}>
     */
    public function producao(array $produtos): array
    {
        $resultado = [];
        foreach (array_chunk(array_values(array_filter(array_map('strval', $produtos), fn ($p) => $p !== '')), 1000) as $bloco) {
            // OPs que ainda têm o que produzir: não fechadas, firmes (prevista não conta) e com saldo (produzido < quantidade).
            $ops = OrdemProducao::whereIn('c2_produto', $bloco)->where('d_e_l_e_t_', '<>', '*')->where('status', '<>', 'fechada')
                ->where('c2_tpop', '<>', 'P')->whereColumn('c2_quje', '<', 'c2_quant')
                ->orderBy('c2_datprf')->orderBy('numero')
                ->get(['id', 'numero', 'c2_produto', 'c2_quant', 'c2_quje', 'c2_datprf', 'status']);
            $ids = $ops->pluck('id')->map(fn ($id) => (int) $id)->flip();
            // Máquinas com OP atual e apontamentos "produzindo" em aberto são poucos (no máximo um por máquina): lidos
            // inteiros e filtrados aqui, sem "IN" com milhares de ids (o SQL Server aceita só 2100 parâmetros).
            $maquinas = $ids->isEmpty() ? collect() : Maquina::whereNotNull('ordem_producao_id')->orderBy('codigo')
                ->get(['id', 'codigo', 'nome', 'situacao', 'ordem_producao_id'])
                ->filter(fn ($m) => isset($ids[(int) $m->ordem_producao_id]))->values();
            // Apontamento real em aberto, do tipo "produzindo", com uma dessas OPs (só máquinas que o usuário vê).
            $produzindo = $ids->isEmpty() ? collect() : ApontamentoEstado::query()
                ->join('legendas', 'legendas.id', '=', 'apontamentos_estado.legenda_id')
                ->whereNull('apontamentos_estado.fim')->where('legendas.tipo', 'produzindo')
                ->whereNotNull('apontamentos_estado.ordem_producao_id')
                ->whereIn('apontamentos_estado.maquina_id', Maquina::query()->select('id'))
                ->get(['apontamentos_estado.maquina_id', 'apontamentos_estado.ordem_producao_id'])
                ->filter(fn ($a) => isset($ids[(int) $a->ordem_producao_id]))->values();
            $maquinasProduzindo = Maquina::whereIn('id', $produzindo->pluck('maquina_id')->unique()->all() ?: [0])
                ->get(['id', 'codigo', 'nome'])->keyBy('id');

            foreach ($ops->groupBy(fn ($op) => rtrim($op->c2_produto)) as $produto => $opsDoProduto) {
                $idsDoProduto = $opsDoProduto->pluck('id')->all();
                $emProducao = $produzindo->whereIn('ordem_producao_id', $idsDoProduto)
                    ->map(fn ($a) => ['maquina' => $maquinasProduzindo[$a->maquina_id] ?? null,
                        'op' => $opsDoProduto->firstWhere('id', $a->ordem_producao_id)])
                    ->filter(fn ($p) => $p['maquina'])->values();
                $direcionadas = $maquinas->whereIn('ordem_producao_id', $idsDoProduto)
                    ->map(fn ($m) => ['maquina' => $m, 'op' => $opsDoProduto->firstWhere('id', $m->ordem_producao_id)])->values();

                $resultado[(string) $produto] = [
                    'situacao' => $emProducao->isNotEmpty() ? 'em_producao' : ($direcionadas->isNotEmpty() ? 'direcionada' : 'com_op'),
                    'ops' => $opsDoProduto->values(),
                    'direcionadas' => $direcionadas,
                    'produzindo' => $emProducao,
                ];
            }
        }

        return $resultado;
    }

    /** Números dos painéis, sobre as faltas do filtro. */
    private function painel(Collection $faltas): array
    {
        $agora = now();
        $abertas = $faltas->filter(fn (FaltaProduto $f) => $f->emAberto());
        $atendimentos = $faltas->map(fn (FaltaProduto $f) => $f->minutosAtendimento())->filter(fn ($m) => $m !== null);

        $idades = array_fill_keys(array_values(self::IDADES), 0);
        foreach ($abertas as $f) {
            $min = $f->minutosEmAberto($agora);
            if ($min === null) {
                continue;
            }
            foreach (self::IDADES as $ate => $rotulo) {
                if ($min <= $ate) {
                    $idades[$rotulo]++;
                    break;
                }
            }
        }

        $porSituacao = array_fill_keys(array_keys(self::PRODUCAO), 0);
        foreach ($abertas as $f) {
            $porSituacao[$f->situacao_producao] = ($porSituacao[$f->situacao_producao] ?? 0) + 1;
        }

        $ranking = $faltas->groupBy(fn (FaltaProduto $f) => $f->produto())
            ->map(fn (Collection $g, $produto) => ['produto' => (string) $produto, 'faltas' => $g->count(),
                'quantidade' => $g->sum('zb5_qtdsol'), 'em_aberto' => $g->filter->emAberto()->count()])
            ->sortByDesc(fn ($l) => [$l['faltas'], $l['quantidade']])->take(10)->values()->all();

        $porStatus = array_fill_keys(array_keys(FaltaProduto::STATUS), 0);
        foreach ($faltas as $f) {
            $porStatus[$f->status()]++;
        }

        return [
            'faltas' => $faltas->count(),
            'produtos' => $faltas->map->produto()->unique()->count(),
            'em_aberto' => $abertas->count(),
            'saldo' => $abertas->sum(fn (FaltaProduto $f) => $f->saldo()),
            'valor_pedidos' => $abertas->sum('zb5_vlped'),
            'atendimento_medio' => $atendimentos->isNotEmpty() ? $atendimentos->avg() : null,
            'atendimento_maior' => $atendimentos->max(),
            'atendidas' => $atendimentos->count(),
            'aberta_mais_antiga' => $abertas->map(fn (FaltaProduto $f) => $f->minutosEmAberto($agora))->filter()->max(),
            'idades' => $idades,
            'por_situacao' => $porSituacao,
            'por_status' => $porStatus,
            'ranking' => $ranking,
        ];
    }

    /**
     * Faltantes (solicitadas no mês, menos as canceladas) × atendidas (concluídas, pelo mês do atendimento), mês a mês.
     * Período do filtro (até 24 meses) ou os últimos 12 meses.
     *
     * @return list<array{chave: string, rotulo: string, faltantes: int, atendidas: int}>
     */
    private function meses(FiltroFaltaProdutos $filtro): array
    {
        $fuso = FiltroFaltaProdutos::fuso();
        $fim = ($filtro->ate ?? CarbonImmutable::now($fuso))->startOfMonth();
        $inicio = ($filtro->de ?? $fim->subMonths(11))->startOfMonth();
        if ($inicio->diffInMonths($fim) > 23) {
            $inicio = $fim->subMonths(23);
        }
        $meses = [];
        for ($m = $inicio; $m->lte($fim); $m = $m->addMonth()) {
            $meses[$m->format('Y-m')] = ['chave' => $m->format('Y-m'), 'rotulo' => self::mesCurto($m), 'faltantes' => 0, 'atendidas' => 0];
        }
        $de = $inicio->utc();
        $ate = $fim->addMonth()->utc();
        $semPeriodo = new FiltroFaltaProdutos(busca: $filtro->busca);

        $this->consulta($semPeriodo)->where('zb5_status', '<>', 'D')
            ->where('solicitado_em', '>=', $de)->where('solicitado_em', '<', $ate)
            ->get(['solicitado_em'])
            ->each(function ($f) use (&$meses, $fuso) {
                $chave = $f->solicitado_em->setTimezone($fuso)->format('Y-m');
                if (isset($meses[$chave])) {
                    $meses[$chave]['faltantes']++;
                }
            });
        $this->consulta($semPeriodo)->where('zb5_status', 'C')
            ->where('atendido_em', '>=', $de)->where('atendido_em', '<', $ate)
            ->get(['atendido_em'])
            ->each(function ($f) use (&$meses, $fuso) {
                $chave = $f->atendido_em->setTimezone($fuso)->format('Y-m');
                if (isset($meses[$chave])) {
                    $meses[$chave]['atendidas']++;
                }
            });

        return array_values($meses);
    }

    private static function mesCurto(CarbonImmutable $m): string
    {
        $nomes = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

        return $nomes[$m->month - 1].'/'.$m->format('y');
    }

    /** Minutos → "2 d 4 h", "5 h 20 min", "45 min". */
    public static function tempo(?float $minutos): string
    {
        if ($minutos === null) {
            return '—';
        }
        $min = (int) round($minutos);
        $d = intdiv($min, 1440);
        $h = intdiv($min % 1440, 60);
        $m = $min % 60;

        return match (true) {
            $d > 0 => $d.' d'.($h ? " {$h} h" : ''),
            $h > 0 => $h.' h'.($m ? " {$m} min" : ''),
            default => $m.' min',
        };
    }
}
