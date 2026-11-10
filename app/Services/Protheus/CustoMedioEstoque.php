<?php

namespace App\Services\Protheus;

use App\Support\Cronometro;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Custo médio de estoque (B2_CM1 da SB2 do Protheus, só lida) por filial + produto + armazém, para valorizar os
 * movimentos da OP que o Protheus ainda não valorizou. Sem o armazém (ou com custo zero nele), vale a média ponderada
 * dos armazéns da filial (soma de B2_VATU1 ÷ soma de B2_QATU) ou, sem saldo, o maior B2_CM1.
 * Uma consulta por filial e bloco de produtos (usa o índice B2_FILIAL + B2_COD + B2_LOCAL), com cache por etapa.
 */
class CustoMedioEstoque
{
    /** "filial|produto" => ['armazens' => [armazém => cm], 'geral' => ?float] */
    private array $cache = [];

    /** @param  iterable<array{filial: string, produto: string}>  $itens */
    public function carregar(iterable $itens): void
    {
        $faltam = [];
        foreach ($itens as $i) {
            $filial = rtrim((string) $i['filial']);
            $produto = rtrim((string) $i['produto']);
            if ($produto !== '' && ! array_key_exists("{$filial}|{$produto}", $this->cache)) {
                $faltam[$filial][$produto] = true;
            }
        }
        $tabela = (string) config('protheus.tabela_sb2', 'SB2');
        if ($faltam && ! preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)?$/', $tabela)) {
            throw new InvalidArgumentException("Nome de tabela SB2 inválido: {$tabela}");
        }
        // Chave de array só com números ("85301") vira inteiro no PHP: tudo volta a texto antes de ir ao SQL Server.
        foreach ($faltam as $filial => $produtos) {
            $filial = (string) $filial;
            foreach (array_chunk(array_map('strval', array_keys($produtos)), 1000) as $bloco) {
                $somas = [];
                foreach ($bloco as $produto) {
                    $this->cache["{$filial}|{$produto}"] = ['armazens' => [], 'geral' => null];
                }
                $linhas = Cronometro::medir('SB2', fn () => DB::connection('protheus')->table($tabela)
                    ->where('B2_FILIAL', $filial)->whereIn('B2_COD', $bloco)->where('D_E_L_E_T_', '<>', '*')
                    ->select('B2_COD', 'B2_LOCAL', 'B2_QATU', 'B2_VATU1', 'B2_CM1')->get());
                foreach ($linhas as $l) {
                    $l = array_change_key_case((array) $l, CASE_UPPER);
                    $produto = rtrim((string) $l['B2_COD']);
                    $chave = "{$filial}|{$produto}";
                    $this->cache[$chave]['armazens'][rtrim((string) $l['B2_LOCAL'])] = (float) $l['B2_CM1'];
                    $somas[$chave]['qtd'] = ($somas[$chave]['qtd'] ?? 0) + max(0, (float) $l['B2_QATU']);
                    $somas[$chave]['valor'] = ($somas[$chave]['valor'] ?? 0) + max(0, (float) $l['B2_VATU1']);
                    $somas[$chave]['cm'] = max($somas[$chave]['cm'] ?? 0, (float) $l['B2_CM1']);
                }
                foreach ($somas as $chave => $s) {
                    $geral = $s['qtd'] > 0 && $s['valor'] > 0 ? $s['valor'] / $s['qtd'] : $s['cm'];
                    $this->cache[$chave]['geral'] = $geral > 0 ? $geral : null;
                }
            }
        }
    }

    /** Custo médio unitário do item (já carregado), ou null se o Protheus não tem custo para ele. */
    public function custo(string $filial, string $produto, string $armazem): ?float
    {
        $item = $this->cache[rtrim($filial).'|'.rtrim($produto)] ?? null;
        if ($item === null) {
            return null;
        }
        $doArmazem = $item['armazens'][rtrim($armazem)] ?? 0;

        return $doArmazem > 0 ? $doArmazem : $item['geral'];
    }
}
