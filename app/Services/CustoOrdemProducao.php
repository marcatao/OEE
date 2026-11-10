<?php

namespace App\Services;

use App\Models\OrdemProducao;
use App\Models\ProdutoProtheus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Custo das ordens de produção a partir dos movimentos do Protheus:
 * - SD3 (movimentos_producao): produções (PR), baixas/consumos (RE) e devoluções (DE), valorizadas pelo D3_CUSTO1;
 * - SD2 (notas_fiscais_op): itens de nota fiscal com OP, valorizados pelo D2_CUSTO1.
 * Só entram movimentos válidos (não excluídos e, na SD3, não estornados).
 * O custo meta vem da SB9 (fechamento mais recente do produto) e fica em ordens_producao.custo_meta.
 */
class CustoOrdemProducao
{
    public function __construct(private TiposMovimento $tipos) {}

    /** Movimentos válidos das duas origens, com as mesmas colunas (consulta "m"). */
    public function movimentos(): Builder
    {
        $sd3 = DB::table('movimentos_producao')->where('d_e_l_e_t_', '<>', '*')->where('d3_estorno', '<>', 'S')->select([
            DB::raw("'SD3' as origem"), 'id', 'd3_filial as filial', 'op', 'op_numero', 'd3_emissao as emissao', 'd3_cf as cf', 'tipo',
            'd3_cod as produto', 'd3_um as um', 'd3_quant as quantidade', 'd3_custo1 as custo', 'd3_doc as documento',
            DB::raw("'' as serie"), 'd3_local as armazem', 'd3_numseq as sequencia', 'r_e_c_n_o_',
        ]);
        $sd2 = DB::table('notas_fiscais_op')->where('d_e_l_e_t_', '<>', '*')->select([
            DB::raw("'SD2' as origem"), 'id', 'd2_filial as filial', 'op', 'op_numero', 'd2_emissao as emissao', DB::raw("'NF' as cf"),
            DB::raw("'nota_fiscal' as tipo"), 'd2_cod as produto', 'd2_um as um', 'd2_quant as quantidade', 'd2_custo1 as custo',
            'd2_doc as documento', 'd2_serie as serie', 'd2_local as armazem', 'd2_item as sequencia', 'r_e_c_n_o_',
        ]);

        return DB::query()->fromSub($sd3->unionAll($sd2), 'm');
    }

    /** Movimentos de uma OP (mesma filial e número). */
    public function movimentosDa(OrdemProducao $op): Builder
    {
        return $this->movimentos()->where('op_numero', $op->numero)->where('filial', $op->c2_filial);
    }

    /**
     * Resumo de custo de várias OPs (ex.: uma página da lista), numa consulta agrupada.
     *
     * @param  iterable<OrdemProducao>  $ops
     * @return array<int, ResumoCustoOp> por id da OP
     */
    public function resumos(iterable $ops): array
    {
        $ops = collect($ops);
        $resumos = [];
        foreach ($ops as $op) {
            $resumos[$op->id] = new ResumoCustoOp($op->custo_meta);
        }
        $porChave = $ops->keyBy(fn ($op) => rtrim($op->c2_filial).'|'.$op->numero);
        foreach ($ops->pluck('numero')->unique()->chunk(1000) as $numeros) {
            $linhas = $this->movimentos()->whereIn('op_numero', $numeros->values()->all())
                ->groupBy('filial', 'op_numero', 'tipo')
                ->select('filial', 'op_numero', 'tipo', DB::raw('sum(quantidade) as qtd'), DB::raw('sum(custo) as valor'), DB::raw('count(*) as linhas'))
                ->get();
            foreach ($linhas as $l) {
                $op = $porChave[rtrim($l->filial).'|'.$l->op_numero] ?? null;
                if ($op) {
                    $resumos[$op->id]->somar($l->tipo, (float) $l->qtd, (float) $l->valor, (int) $l->linhas);
                }
            }
        }

        return $resumos;
    }

    /**
     * Kardex da OP: movimentos em ordem de data, com acumulados (produzido, custo líquido e custo unitário), o
     * resumo e o consumo por componente.
     *
     * @return array{linhas: list<object>, resumo: ResumoCustoOp, componentes: list<array>, produtos: Collection}
     */
    public function kardex(OrdemProducao $op): array
    {
        $resumo = new ResumoCustoOp($op->custo_meta);
        $produzido = 0.0;
        $custo = 0.0;
        $componentes = [];
        $linhas = [];
        foreach ($this->movimentosDa($op)->orderBy('emissao')->orderBy('origem')->orderBy('sequencia')->orderBy('r_e_c_n_o_')->get() as $m) {
            $qtd = (float) $m->quantidade;
            $valor = (float) $m->custo;
            $resumo->somar($m->tipo, $qtd, $valor);
            $sinal = match ($m->tipo) {
                'consumo' => 1, 'devolucao' => -1, default => 0
            };
            if ($m->tipo === 'producao') {
                $produzido += $qtd;
            }
            if ($sinal !== 0) {
                $custo += $sinal * $valor;
                $cod = rtrim($m->produto);
                $componentes[$cod] ??= ['produto' => $cod, 'um' => $m->um, 'quantidade' => 0.0, 'valor' => 0.0];
                $componentes[$cod]['quantidade'] += $sinal * $qtd;
                $componentes[$cod]['valor'] += $sinal * $valor;
            }
            $m->descricao_cf = $this->tipos->descricao($m->tipo === 'nota_fiscal' ? 'NF' : $m->cf);
            $m->produzido_acumulado = $produzido;
            $m->custo_acumulado = $custo;
            $m->unitario_acumulado = $produzido > 0 ? $custo / $produzido : null;
            $linhas[] = $m;
        }
        usort($componentes, fn ($a, $b) => $b['valor'] <=> $a['valor']);

        $codigos = array_unique([rtrim($op->c2_produto), ...array_map(fn ($m) => rtrim($m->produto), $linhas)]);

        return ['linhas' => $linhas, 'resumo' => $resumo, 'componentes' => $componentes, 'produtos' => $this->produtos($codigos)];
    }

    /** Descrição dos produtos (SB1) pelos códigos. @return Collection<string, ProdutoProtheus> */
    public function produtos(iterable $codigos): Collection
    {
        $produtos = collect();
        foreach (collect($codigos)->map(fn ($c) => rtrim((string) $c))->filter()->unique()->chunk(1000) as $bloco) {
            $produtos = $produtos->merge(ProdutoProtheus::whereIn('b1_cod', $bloco->values()->all())->get()->keyBy(fn ($p) => $p->codigo()));
        }

        return $produtos;
    }

    /**
     * Grava ordens_producao.custo_meta das OPs informadas: custo unitário do fechamento mais recente (maior B9_DATA)
     * do produto na SB9 do Protheus, na filial da OP (ou, se não houver, na filial em branco = compartilhada). Com
     * vários armazéns no mesmo fechamento, vale o custo médio ponderado (soma de B9_VINI1 ÷ soma de B9_QINI); sem
     * saldo, o maior B9_CM1. Só grava as que mudaram, em poucos comandos (agrupadas pelo valor). A SB9 não é copiada.
     *
     * @param  list<int>  $ids
     * @return list<int> ids alterados
     */
    public function atualizarMetas(array $ids): array
    {
        $alterados = [];
        foreach (array_chunk($ids, 1000) as $bloco) {
            $ops = OrdemProducao::whereIn('id', $bloco)->get(['id', 'c2_filial', 'c2_produto', 'custo_meta']);
            $custos = $this->custosSb9(
                $ops->pluck('c2_produto')->map(fn ($p) => rtrim($p))->filter()->unique()->values()->all(),
                $ops->pluck('c2_filial')->map(fn ($f) => rtrim($f))->push('')->unique()->values()->all(),
            );
            $porValor = [];
            foreach ($ops as $op) {
                $porFilial = $custos[rtrim($op->c2_produto)] ?? [];
                $escolhido = $porFilial[rtrim($op->c2_filial)] ?? $porFilial[''] ?? null;
                $novo = $escolhido !== null ? round($escolhido['valor'], 6) : null;
                $atual = $op->custo_meta !== null ? round((float) $op->custo_meta, 6) : null;
                if ($novo !== $atual && ($novo === null || $atual === null || abs($novo - $atual) > 0.000001)) {
                    $porValor[$novo === null ? 'nulo' : (string) $novo][] = $op->id;
                }
            }
            foreach ($porValor as $valor => $idsDoValor) {
                DB::table('ordens_producao')->whereIn('id', $idsDoValor)
                    ->update(['custo_meta' => $valor === 'nulo' ? null : (float) $valor]);
                array_push($alterados, ...$idsDoValor);
            }
        }

        return $alterados;
    }

    /**
     * Custo do fechamento mais recente de cada produto e filial na SB9.
     *
     * A filial vem na condição para o SQL Server usar o índice da SB9 (B9_FILIAL + B9_COD + ...): sem ela, cada
     * lote varria a SB9 inteira e a sincronização das OPs ficava lenta.
     *
     * @param  list<string>  $produtos
     * @param  list<string>  $filiais
     * @return array<string, array<string, array{data: string, valor: float}>> produto → filial → custo
     */
    private function custosSb9(array $produtos, array $filiais): array
    {
        if (! $produtos) {
            return [];
        }
        $tabela = (string) config('protheus.tabela_sb9', 'SB9');
        if (! preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)?$/', $tabela)) {
            throw new \InvalidArgumentException("Nome de tabela SB9 inválido: {$tabela}");
        }
        $linhas = DB::connection('protheus')->table("{$tabela} as b")
            ->select('b.B9_FILIAL', 'b.B9_COD', 'b.B9_DATA', 'b.B9_QINI', 'b.B9_VINI1', 'b.B9_CM1')
            ->whereIn('b.B9_FILIAL', $filiais)
            ->whereIn('b.B9_COD', $produtos)
            ->where('b.D_E_L_E_T_', '<>', '*')
            ->where('b.B9_DATA', '=', fn ($q) => $q->from("{$tabela} as x")->selectRaw('max(x.B9_DATA)')
                ->whereColumn('x.B9_COD', 'b.B9_COD')->whereColumn('x.B9_FILIAL', 'b.B9_FILIAL')->where('x.D_E_L_E_T_', '<>', '*'))
            ->get();

        $grupos = [];
        foreach ($linhas as $l) {
            $l = array_change_key_case((array) $l, CASE_UPPER);
            $g = &$grupos[rtrim((string) $l['B9_COD'])][rtrim((string) $l['B9_FILIAL'])];
            $g ??= ['data' => (string) $l['B9_DATA'], 'qtd' => 0.0, 'valor' => 0.0, 'cm' => 0.0];
            $g['qtd'] += (float) $l['B9_QINI'];
            $g['valor'] += (float) $l['B9_VINI1'];
            $g['cm'] = max($g['cm'], (float) $l['B9_CM1']);
            unset($g);
        }
        $custos = [];
        foreach ($grupos as $produto => $filiais) {
            foreach ($filiais as $filial => $g) {
                $custos[$produto][$filial] = ['data' => $g['data'], 'valor' => $g['qtd'] > 0 ? $g['valor'] / $g['qtd'] : $g['cm']];
            }
        }

        return $custos;
    }
}
