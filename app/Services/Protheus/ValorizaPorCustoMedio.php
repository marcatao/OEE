<?php

namespace App\Services\Protheus;

use App\Support\Cronometro;
use Illuminate\Support\Facades\DB;

/**
 * Para importadores de movimentos de OP (SD3, SD2): depois de cada lote, grava em "custo_medio" o custo médio de
 * estoque atual do item (SB2), usado quando o Protheus ainda não valorizou o movimento. Só grava o que mudou.
 */
trait ValorizaPorCustoMedio
{
    /** @return array{0: string, 1: string, 2: string} colunas do portal: filial, produto, armazém */
    abstract protected function colunasDoItem(): array;

    protected function recalculaLote(): bool
    {
        return true;
    }

    protected function depoisDoLote(array $idsDoLote, array $alterados): array
    {
        [$filial, $produto, $armazem] = $this->colunasDoItem();
        $estoque = $this->custoMedioEstoque ??= app(CustoMedioEstoque::class); // um por etapa (cache)
        $mudaram = [];
        foreach (array_chunk($idsDoLote, 1000) as $bloco) {
            $linhas = DB::table($this->tabela())->whereIn('id', $bloco)->get(['id', $filial, $produto, $armazem, 'custo_medio']);
            $estoque->carregar($linhas->map(fn ($l) => ['filial' => $l->{$filial}, 'produto' => $l->{$produto}]));
            $porValor = [];
            foreach ($linhas as $l) {
                $novo = $estoque->custo((string) $l->{$filial}, (string) $l->{$produto}, (string) $l->{$armazem});
                $novo = $novo !== null ? round($novo, 6) : null;
                $atual = $l->custo_medio !== null ? round((float) $l->custo_medio, 6) : null;
                if ($novo !== $atual && ($novo === null || $atual === null || abs($novo - $atual) > 0.000001)) {
                    $porValor[$novo === null ? 'nulo' : (string) $novo][] = $l->id;
                }
            }
            Cronometro::medir('portal', function () use ($porValor, &$mudaram) {
                foreach ($porValor as $valor => $ids) {
                    DB::table($this->tabela())->whereIn('id', $ids)->update(['custo_medio' => $valor === 'nulo' ? null : (float) $valor]);
                    array_push($mudaram, ...$ids);
                }
            });
        }

        return $mudaram;
    }
}
