<?php

namespace App\Services\Calendario;

use Carbon\CarbonImmutable;

/**
 * Feriados que já vêm carregados num calendário novo: nacionais, do Estado de São Paulo e de São Bernardo do Campo.
 * Os administradores podem mudar qualquer dia depois (ex.: tirar o Carnaval, marcar pontes).
 */
class Feriados
{
    /** @return array<string, string> "AAAA-MM-DD" => descrição */
    public function doAno(int $ano): array
    {
        $pascoa = self::pascoa($ano);
        $dias = [
            "{$ano}-01-01" => 'Confraternização Universal',
            $pascoa->subDays(48)->format('Y-m-d') => 'Carnaval (ponto facultativo)',
            $pascoa->subDays(47)->format('Y-m-d') => 'Carnaval (ponto facultativo)',
            $pascoa->subDays(2)->format('Y-m-d') => 'Sexta-feira Santa',
            "{$ano}-04-21" => 'Tiradentes',
            "{$ano}-05-01" => 'Dia do Trabalho',
            $pascoa->addDays(60)->format('Y-m-d') => 'Corpus Christi (São Bernardo do Campo)',
            "{$ano}-07-09" => 'Revolução Constitucionalista (SP)',
            "{$ano}-08-20" => 'Aniversário de São Bernardo do Campo',
            "{$ano}-09-07" => 'Independência do Brasil',
            "{$ano}-10-12" => 'Nossa Senhora Aparecida',
            "{$ano}-11-02" => 'Finados',
            "{$ano}-11-15" => 'Proclamação da República',
            "{$ano}-11-20" => 'Dia Nacional de Zumbi e da Consciência Negra',
            "{$ano}-12-25" => 'Natal',
        ];
        ksort($dias);

        return $dias;
    }

    /** Domingo de Páscoa (algoritmo de Meeus/Jones/Butcher; não depende da extensão calendar do PHP). */
    public static function pascoa(int $ano): CarbonImmutable
    {
        $a = $ano % 19;
        $b = intdiv($ano, 100);
        $c = $ano % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($ano, $mes, $dia);
    }
}
