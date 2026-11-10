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

    /** 1234.5 → "R$ 1.234,50"; null → "—". */
    public static function moeda(?float $valor, int $casas = 2): string
    {
        return $valor === null ? '—' : 'R$ '.number_format($valor, $casas, ',', '.');
    }

    /** Quantidade com casas só quando tem fração: 1500 → "1.500"; 12.5 → "12,50". */
    public static function quantidade(?float $valor): string
    {
        if ($valor === null) {
            return '—';
        }

        return number_format($valor, abs($valor - round($valor)) < 0.00001 ? 0 : 2, ',', '.');
    }

    /** Divergência do custo com a meta: 0.123 → "+12,3%"; -0.05 → "−5,0%". */
    public static function divergencia(?float $valor): string
    {
        if ($valor === null) {
            return '—';
        }

        return ($valor > 0 ? '+' : ($valor < 0 ? '−' : '')).number_format(abs($valor) * 100, 1, ',', '.').'%';
    }

    /** Classe da divergência: acima da meta além da tolerância (ruim), abaixo (bom) ou dentro. */
    public static function nivelDivergencia(?float $valor): string
    {
        $tolerancia = (float) config('oee.custo_tolerancia', 0.05);

        return match (true) {
            $valor === null => 'div-sem',
            $valor > $tolerancia => 'div-acima',
            $valor < -$tolerancia => 'div-abaixo',
            default => 'div-dentro',
        };
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
