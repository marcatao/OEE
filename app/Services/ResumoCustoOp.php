<?php

namespace App\Services;

/**
 * Totais dos movimentos de uma OP e o custo de 1 peça:
 * - custo Protheus = valor gravado pelo Protheus nas produções (PR, D3_CUSTO1) ÷ quantidade produzida;
 * - custo simulado (OEE) = baixas/consumos (RE) da OP distribuídos nas produções (PR) proporcionalmente às peças de cada
 *   uma — por peça: baixas ÷ produzido;
 * - custo meta = último fechamento do produto (SB9);
 * - divergência = (simulado − meta) ÷ meta.
 * Consumos líquidos (baixas + notas com OP − devoluções) continuam como referência (custoUnitario()).
 */
class ResumoCustoOp
{
    public float $producaoQtd = 0;

    public float $producaoValor = 0;

    public float $consumoQtd = 0;

    public float $consumoValor = 0;

    public float $devolucaoQtd = 0;

    public float $devolucaoValor = 0;

    public float $notaQtd = 0;

    public float $notaValor = 0;

    public float $outrosValor = 0;

    public int $movimentos = 0;

    /** Consumos valorizados pelo custo médio de estoque (o Protheus ainda não gravou o custo). */
    public int $pelaMedia = 0;

    /** Consumos sem custo nem no Protheus nem no estoque (entram com zero). */
    public int $semCusto = 0;

    /** Produções (PR) que o Protheus ainda não valorizou: o custo Protheus fica incompleto. */
    public int $producaoSemCusto = 0;

    public function __construct(public ?float $meta = null) {}

    public function somar(string $tipo, float $quantidade, float $valor, int $linhas = 1): void
    {
        $this->movimentos += $linhas;
        match ($tipo) {
            'producao' => [$this->producaoQtd += $quantidade, $this->producaoValor += $valor],
            'consumo' => [$this->consumoQtd += $quantidade, $this->consumoValor += $valor],
            'devolucao' => [$this->devolucaoQtd += $quantidade, $this->devolucaoValor += $valor],
            'nota_fiscal' => [$this->notaQtd += $quantidade, $this->notaValor += $valor],
            default => $this->outrosValor += $valor,
        };
    }

    public function temMovimentos(): bool
    {
        return $this->movimentos > 0;
    }

    /** Consumos da OP (R$): baixas + notas com OP − devoluções. */
    public function custoLiquido(): float
    {
        return $this->consumoValor + $this->notaValor - $this->devolucaoValor;
    }

    /** Custo por unidade produzida; null sem produção. */
    public function custoUnitario(): ?float
    {
        return $this->producaoQtd > 0 ? $this->custoLiquido() / $this->producaoQtd : null;
    }

    /** Custo por peça gravado pelo Protheus nas produções (valor PR ÷ produzido); null sem produção. */
    public function custoProtheus(): ?float
    {
        return $this->producaoQtd > 0 ? $this->producaoValor / $this->producaoQtd : null;
    }

    /**
     * Custo simulado (OEE) por peça: as baixas/consumos (RE) da OP distribuídas nas produções proporcionalmente às
     * peças de cada produção = baixas ÷ produzido. null sem produção.
     */
    public function custoSimulado(): ?float
    {
        return $this->producaoQtd > 0 ? $this->consumoValor / $this->producaoQtd : null;
    }

    /** Parte das baixas que cabe a uma produção de $quantidade peças (rateio pela quantidade). */
    public function rateioDaProducao(float $quantidade): ?float
    {
        return $this->producaoQtd > 0 ? $this->consumoValor * $quantidade / $this->producaoQtd : null;
    }

    /** Quanto a produção deveria ter custado pelo custo meta (meta × produzido). */
    public function custoMetaTotal(): ?float
    {
        return $this->meta !== null ? $this->meta * $this->producaoQtd : null;
    }

    /** (simulado − meta) ÷ meta: 0,1 = 10% acima da meta; negativo = abaixo. null sem meta ou sem produção. */
    public function divergencia(): ?float
    {
        $atual = $this->custoSimulado();

        return $atual !== null && $this->meta !== null && $this->meta > 0 ? ($atual - $this->meta) / $this->meta : null;
    }
}
