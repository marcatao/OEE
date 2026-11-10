<?php

namespace App\Services\Protheus;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Quando a sincronização de movimentos com OP (SD3, SD2) não traz nenhuma linha, explica o motivo: o campo da OP
 * configurado está vazio em toda a tabela? Há outro campo de OP preenchido (ex.: D3_OP)? O corte de data tirou tudo?
 */
trait DiagnosticoCampoOp
{
    public function diagnosticoSemLinhas(string $tabelaOrigem): ?string
    {
        $campo = self::campoOp();
        $prefixo = strstr($campo, '_', true).'_'; // D3_ ou D2_
        $desde = preg_replace('/\D/', '', (string) config('protheus.sc2_desde'));
        $corte = strlen($desde) === 8 ? ' (com emissão a partir de '.substr($desde, 6, 2).'/'.substr($desde, 4, 2).'/'.substr($desde, 0, 4).', PROTHEUS_SC2_DESDE)' : '';
        $texto = "Nenhuma linha com {$campo} preenchido{$corte}.";

        try {
            $colunas = array_map('strtoupper', Schema::connection('protheus')->getColumnListing($tabelaOrigem));
            $candidatos = array_slice(array_values(array_filter($colunas, fn ($c) => $c !== $campo
                && str_starts_with($c, $prefixo) && preg_match('/^'.$prefixo.'(OP|ORD|NUMOP)/', $c))), 0, 4);
            $preenchidos = array_values(array_filter($candidatos,
                fn ($c) => DB::connection('protheus')->table($tabelaOrigem)->where($c, '<>', '')->exists()));
        } catch (Throwable) {
            return $texto.' Confira no Protheus qual campo guarda a OP.';
        }

        $variavel = $prefixo === 'D3_' ? 'PROTHEUS_SD3_CAMPO_OP' : 'PROTHEUS_SD2_CAMPO_OP';
        if ($preenchidos) {
            return $texto.' Campos de OP com dados nesta tabela: '.implode(', ', $preenchidos)
                .". Para usar, ponha {$variavel}={$preenchidos[0]} no .env e sincronize de novo.";
        }

        return $texto.($candidatos ? ' Os outros campos de OP ('.implode(', ', $candidatos).') também estão vazios.' : '')
            ." Confira no Protheus qual campo guarda a OP e informe em {$variavel} no .env.";
    }
}
