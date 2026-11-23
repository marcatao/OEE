<?php

namespace App\Support;

/**
 * Tempos em segundos com fração (milissegundos; pedido do usuário em 23/11/2026: máquinas que fazem mais de uma peça
 * por segundo). Vírgula decimal, como nas telas.
 */
class Segundos
{
    /** Ciclo médio: abaixo de 10 s com 3 casas ("0,040 s", "2,350 s"); acima, 1 casa ("32,0 s"). "—" sem valor. */
    public static function ciclo(?float $segundos): string
    {
        if ($segundos === null) {
            return '—';
        }

        return number_format($segundos, $segundos < 10 ? 3 : 1, ',', '.').' s';
    }

    /** Limite do cadastro (micro/macro parada): até 3 casas, sem zeros à direita ("0,5", "60", "1,25"). */
    public static function limite(?float $segundos): string
    {
        if ($segundos === null) {
            return '-';
        }
        $texto = rtrim(rtrim(number_format($segundos, 3, ',', '.'), '0'), ',');

        return $texto === '' ? '0' : $texto;
    }

    /** Valor para o campo numérico do formulário (ponto decimal, até 3 casas, sem zeros à direita). */
    public static function campo(?float $segundos): string
    {
        return $segundos === null ? '' : rtrim(rtrim(number_format($segundos, 3, '.', ''), '0'), '.');
    }
}
