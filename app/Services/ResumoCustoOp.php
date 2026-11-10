<?php

namespace App\Services;

/**
 * Totais dos movimentos de uma OP e o custo calculado (o que importa é o custo de 1 peça):
 * consumos = baixas (RE, SD3) + notas com OP (SD2) − devoluções (DE); custo unitário atual = consumos ÷ quantidade
 * produzida (PR) na própria OP. A divergência compara com o custo meta unitário (último fechamento do produto, SB9).
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

    /** Quanto a produção deveria ter custado pelo custo meta (meta × produzido). */
    public function custoMetaTotal(): ?float
    {
        return $this->meta !== null ? $this->meta * $this->producaoQtd : null;
    }

    /** (atual − meta) ÷ meta: 0,1 = 10% acima da meta; negativo = abaixo. null sem meta ou sem produção. */
    public function divergencia(): ?float
    {
        $atual = $this->custoUnitario();

        return $atual !== null && $this->meta !== null && $this->meta > 0 ? ($atual - $this->meta) / $this->meta : null;
    }
}
