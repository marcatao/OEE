<?php

namespace App\Services;

use App\Models\Ferramenta;
use App\Models\OrdemProducao;
use App\Models\ProdutoProtheus;

/**
 * Meta de produção em peças por minuto (regra do usuário, 23/11/2026).
 *
 * - A meta vem da ESTRUTURA do produto da OP no Protheus (SG1 vigente na revisão da OP): o componente do tipo MO (mão
 *   de obra, B1_TIPO = 'MO' na SB1) traz em G1_QUANT as HORAS por peça, em horas CENTESIMAIS (base 100), para a
 *   quantidade base do produto (B1_QB, vazio = 1). Ex.: produto 0130066, item MO-1135 com 0,00034 h →
 *   0,00034 × 60 = 0,0204 min por peça → meta = 1 ÷ 0,0204 ≈ 49 pç/min.
 * - meta (pç/min) = 1 ÷ (G1_QUANT ÷ B1_QB × 60). Só UM item MO por estrutura: com mais de um, vale o primeiro (pela
 *   sequência TRT e pelo código), sem somar. Sem item MO (ou quantidade 0) = sem meta (null).
 * - A meta é FIXA: não cai quando o molde trabalha com menos cavidades. Com cavidades desativadas e produção abaixo
 *   da meta, o molde é indicado para conserto (moldeParaConserto).
 * - A meta alimenta a PERFORMANCE do OEE pelo IoT (CalculadoraOee::performance).
 */
class MetaProducao
{
    /** @var array<int, ?array> meta já calculada por OP nesta requisição */
    private array $cache = [];

    public function __construct(private EngenhariaOrdemProducao $engenharia) {}

    /** Meta da OP em peças por minuto (null sem item MO na estrutura). */
    public function porMinuto(?OrdemProducao $op): ?float
    {
        return $this->daOp($op)['por_minuto'] ?? null;
    }

    /**
     * Meta da OP com o item MO de onde ela veio.
     *
     * @return array{por_minuto: float, item: string, horas: float, base: float, minutos_por_peca: float}|null
     */
    public function daOp(?OrdemProducao $op): ?array
    {
        if (! $op) {
            return null;
        }
        $id = (int) $op->id;
        if (array_key_exists($id, $this->cache)) {
            return $this->cache[$id];
        }

        $linhas = $this->engenharia->linhasVigentes($op);
        $tipos = $linhas->isEmpty() ? collect() : ProdutoProtheus::where('d_e_l_e_t_', '<>', '*')->whereIn('b1_cod', $linhas->pluck('g1_comp')->map(fn ($c) => rtrim($c))->unique()->values()->all())
            ->pluck('b1_tipo', 'b1_cod')->mapWithKeys(fn ($t, $c) => [rtrim($c) => strtoupper(trim((string) $t))]);
        $mo = $linhas->first(fn ($l) => ($tipos[rtrim($l->g1_comp)] ?? null) === 'MO');

        $base = (float) (ProdutoProtheus::where('b1_cod', rtrim((string) $op->c2_produto))->value('b1_qb') ?: 1);

        return $this->cache[$id] = $mo ? self::calcular((float) $mo->g1_quant, $base, rtrim($mo->g1_comp)) : null;
    }

    /**
     * A conta da meta: horas centesimais por "base" peças → peças por minuto.
     *
     * @return array{por_minuto: float, item: string, horas: float, base: float, minutos_por_peca: float}|null
     */
    public static function calcular(float $horas, float $base = 1, string $item = ''): ?array
    {
        $base = $base > 0 ? $base : 1;
        $minutosPorPeca = $horas / $base * 60;
        if ($minutosPorPeca <= 0) {
            return null;
        }

        return ['por_minuto' => round(1 / $minutosPorPeca, 2), 'item' => $item, 'horas' => $horas, 'base' => $base,
            'minutos_por_peca' => $minutosPorPeca];
    }

    /**
     * Molde para conserto: a máquina está produzindo com um molde de cavidades DESATIVADAS (ativas < H4_QTDCAV) e as
     * peças por minuto estão ABAIXO da meta. Devolve o molde (o primeiro nessa condição) ou null.
     *
     * @param  list<array{codigo: string, ferramenta: ?Ferramenta}>  $moldes  EngenhariaOrdemProducao::moldesDaMaquina
     * @return array{codigo: string, descricao: string, ativas: int, total: int}|null
     */
    public static function moldeParaConserto(array $moldes, float $pecasPorMinuto, ?float $meta): ?array
    {
        if ($meta === null || $meta <= 0 || $pecasPorMinuto <= 0 || $pecasPorMinuto >= $meta) {
            return null;
        }
        foreach ($moldes as $molde) {
            $f = $molde['ferramenta'] ?? null;
            if ($f && (int) $f->h4_qtdcav > 0 && (int) $f->h4_qtdcavativas < (int) $f->h4_qtdcav) {
                return ['codigo' => (string) $molde['codigo'], 'descricao' => trim((string) $f->h4_descri),
                    'ativas' => (int) $f->h4_qtdcavativas, 'total' => (int) $f->h4_qtdcav];
            }
        }

        return null;
    }
}
