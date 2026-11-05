<?php

namespace App\Support;

/**
 * Formatação dos números das telas (padrão brasileiro). "Sem dados" aparece como travessão, nunca 0%.
 */
final class Formato
{
    public static function pct(?float $valor, int $casas = 1): string
    {
        return $valor === null ? '—' : number_format($valor * 100, $casas, ',', '.').'%';
    }

    /** Valor de 0 a 100 para os medidores (CSS --pct). */
    public static function pct100(?float $valor): float
    {
        return $valor === null ? 0 : round(max(min($valor, 1), 0) * 100, 1);
    }

    /** Faixas do OEE: ≥ 85% classe mundial (bom), 60–85% típico (médio), < 60% baixo (ruim). */
    public static function nivel(?float $valor): string
    {
        return match (true) {
            $valor === null => 'nivel-sem',
            $valor >= 0.85 => 'nivel-bom',
            $valor >= 0.60 => 'nivel-medio',
            default => 'nivel-ruim',
        };
    }

    public static function inteiro(int|float $valor): string
    {
        return number_format($valor, 0, ',', '.');
    }

    /** 135.5 → "2 h 16 min"; 40 → "40 min". */
    public static function minutos(float $minutos): string
    {
        $total = (int) round($minutos);
        $h = intdiv($total, 60);
        $m = $total % 60;

        return $h > 0 ? "{$h} h ".str_pad((string) $m, 2, '0', STR_PAD_LEFT).' min' : "{$m} min";
    }
}
