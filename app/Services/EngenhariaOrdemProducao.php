<?php

namespace App\Services;

use App\Models\EmpenhoOp;
use App\Models\EstruturaProduto;
use App\Models\Ferramenta;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use App\Models\ProdutoProtheus;
use App\Models\RoteiroOperacao;
use Illuminate\Support\Collection;

/**
 * Engenharia de uma OP, pelos relacionamentos do Protheus (só leitura, das cópias sincronizadas):
 * - Empenhos (SD4): D4_FILIAL = C2_FILIAL e D4_OP = C2_NUM + C2_ITEM + C2_SEQUEN (empenhos_op.op_numero = numero).
 * - Estrutura (SG1): G1_COD = C2_PRODUTO, vigente HOJE (G1_INI..G1_FIM) e, se a OP tem revisão (C2_REVISAO), da
 *   revisão (G1_REVINI..G1_REVFIM). Necessidade da OP = G1_QUANT ÷ B1_QB (base) × C2_QUANT ÷ (1 − G1_PERDA%)
 *   (G1_FIXVAR = 'F': quantidade fixa, sem multiplicar pela OP).
 *   O componente casa com o empenho por D4_COD = G1_COMP e D4_TRT = G1_TRT.
 * - Roteiro (SG2): G2_PRODUTO = C2_PRODUTO e G2_CODIGO = C2_ROTEIRO (em branco: o roteiro "01", ou o primeiro do produto).
 *   G2_CTRAB = máquina do portal (SHB) e G2_FERRAM = ferramenta/molde (SH4.H4_CODIGO).
 * SG1, SG2 e SH4 podem ser compartilhadas entre filiais (filial em branco): valem a filial da OP e a em branco.
 */
class EngenhariaOrdemProducao
{
    public function __construct(private CustoOrdemProducao $custo) {}

    /** @return list<string> */
    private static function filiais(OrdemProducao $op): array
    {
        return array_values(array_unique([rtrim((string) $op->c2_filial), '']));
    }

    /** Empenhos válidos da OP (SD4). */
    public function empenhos(OrdemProducao $op): Collection
    {
        return EmpenhoOp::validos()->where('op_numero', $op->numero)->where('d4_filial', rtrim((string) $op->c2_filial))
            ->orderBy('d4_cod')->orderBy('d4_trt')->orderBy('r_e_c_n_o_')->get();
    }

    /**
     * Estrutura atual do produto da OP (1º nível), com a necessidade para a quantidade da OP e o empenhado.
     *
     * @return Collection<int, EstruturaProduto> com os atributos extras necessidade, empenhado, saldo_empenho, tem_estrutura
     */
    public function estrutura(OrdemProducao $op, ?Collection $empenhos = null): Collection
    {
        $hoje = now(config('oee.fuso_exibicao'))->format('Ymd');
        $revisao = trim((string) $op->c2_revisao);
        $linhas = EstruturaProduto::validas()->vigentes($hoje)
            ->where('g1_cod', rtrim((string) $op->c2_produto))->whereIn('g1_filial', self::filiais($op))
            ->when($revisao !== '', fn ($q) => $q->where(fn ($w) => $w->where('g1_revini', '')->orWhere('g1_revini', '<=', $revisao))
                ->where(fn ($w) => $w->where('g1_revfim', '')->orWhere('g1_revfim', '>=', $revisao)))
            ->orderBy('g1_trt')->orderBy('g1_comp')->get();

        $empenhos ??= $this->empenhos($op);
        $comEstrutura = $linhas->isEmpty() ? collect() : EstruturaProduto::validas()
            ->whereIn('g1_cod', $linhas->pluck('g1_comp')->map(fn ($c) => rtrim($c))->unique()->values()->all())
            ->distinct()->pluck('g1_cod')->map(fn ($c) => rtrim($c))->flip();

        // Quantidade base da estrutura do produto (B1_QB): G1_QUANT vale para "base" unidades do pai (vazio/0 = 1).
        $base = (float) (ProdutoProtheus::where('b1_cod', rtrim((string) $op->c2_produto))->value('b1_qb') ?: 1);
        $porComponente = $linhas->groupBy(fn ($l) => rtrim($l->g1_comp))->map->count();

        foreach ($linhas as $l) {
            // Como a explosão do Protheus: por peça = G1_QUANT ÷ base; com perda, ÷ (1 − perda%); fixa não multiplica.
            $perda = (float) $l->g1_perda > 0 && (float) $l->g1_perda < 100 ? 100 / (100 - (float) $l->g1_perda) : 1.0;
            $l->base = $base;
            $l->por_peca = $l->g1_quant / $base;
            $l->necessidade = (strtoupper(trim((string) $l->g1_fixvar)) === 'F' ? $l->g1_quant : $l->por_peca * (float) $op->c2_quant) * $perda;
            // Empenho pela sequência (TRT); componente que aparece uma vez só na estrutura aceita qualquer TRT.
            $doComponente = $empenhos->filter(fn ($e) => rtrim($e->d4_cod) === rtrim($l->g1_comp)
                && (trim($e->d4_trt) === trim($l->g1_trt) || ($porComponente[rtrim($l->g1_comp)] ?? 0) === 1));
            $l->empenhado = $doComponente->isEmpty() ? null : $doComponente->sum('d4_qtdeori');
            $l->saldo_empenho = $doComponente->isEmpty() ? null : $doComponente->sum('d4_quant');
            $l->tem_estrutura = isset($comEstrutura[rtrim($l->g1_comp)]);
        }

        return $linhas;
    }

    /**
     * Roteiro da OP: operações (SG2), máquinas (centro de trabalho) e ferramentas/moldes (SH4).
     *
     * @return array{codigo: ?string, informado: bool, outros: list<string>, operacoes: Collection, maquinas: Collection, ferramentas: Collection}
     */
    public function roteiro(OrdemProducao $op, bool $comMaquinas = true, bool $todasUnidades = false): array
    {
        $todas = RoteiroOperacao::validas()->where('g2_produto', rtrim((string) $op->c2_produto))->whereIn('g2_filial', self::filiais($op))
            ->orderBy('g2_codigo')->orderBy('g2_operac')->get();
        $codigos = $todas->pluck('g2_codigo')->map(fn ($c) => trim($c))->unique()->values();
        $informado = trim((string) $op->c2_roteiro);
        $codigo = $informado !== '' ? $informado : ($codigos->contains('01') ? '01' : $codigos->first());
        $operacoes = $todas->filter(fn ($o) => trim($o->g2_codigo) === (string) $codigo)->values();

        $ctrab = $operacoes->pluck('g2_ctrab')->map(fn ($c) => rtrim($c))->filter()->unique()->values()->all();
        $ferram = $operacoes->pluck('g2_ferram')->map(fn ($c) => rtrim($c))->filter()->unique()->values()->all();

        return [
            'codigo' => $codigo,
            'informado' => $informado !== '',
            'outros' => $codigos->reject(fn ($c) => $c === (string) $codigo)->values()->all(),
            'operacoes' => $operacoes,
            // Só as que o usuário enxerga (unidade).
            'maquinas' => $ctrab && $comMaquinas ? Maquina::whereIn('codigo', $ctrab)->get()->keyBy('codigo') : collect(),
            'ferramentas' => $ferram ? Ferramenta::query()->when($todasUnidades, fn ($q) => $q->withoutGlobalScope('unidade'))->validas()->whereIn('h4_codigo', $ferram)->whereIn('h4_filial', self::filiais($op))->get()
                ->keyBy(fn ($f) => $f->codigo()) : collect(),
            'ferramentas_codigos' => $ferram,
        ];
    }

    /**
     * Moldes (SH4) da OP para a máquina: os das operações do roteiro cujo centro de trabalho é a máquina; se nenhuma
     * operação for dela, os de todo o roteiro da OP.
     *
     * @return list<array{codigo: string, ferramenta: ?Ferramenta}>
     */
    public static function moldesDaMaquina(array $roteiro, string $codigoMaquina): array
    {
        $daMaquina = $roteiro['operacoes']->filter(fn ($o) => rtrim($o->g2_ctrab) === rtrim($codigoMaquina));
        $codigos = ($daMaquina->isNotEmpty() ? $daMaquina : $roteiro['operacoes'])
            ->pluck('g2_ferram')->map(fn ($c) => rtrim($c))->filter()->unique()->values();

        return $codigos->map(fn ($c) => ['codigo' => $c, 'ferramenta' => $roteiro['ferramentas'][$c] ?? null])->all();
    }

    /** Tudo para a tela da OP, com as descrições dos produtos citados. */
    public function daOp(OrdemProducao $op): array
    {
        $empenhos = $this->empenhos($op);
        $estrutura = $this->estrutura($op, $empenhos);

        return [
            'empenhos' => $empenhos,
            'estrutura' => $estrutura,
            'roteiro' => $this->roteiro($op),
            'produtosEngenharia' => $this->custo->produtos($empenhos->pluck('d4_cod')->merge($estrutura->pluck('g1_comp'))),
        ];
    }
}
