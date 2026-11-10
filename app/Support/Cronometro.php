<?php

namespace App\Support;

/**
 * Soma o tempo gasto em cada parte de uma tarefa longa (ex.: uma etapa da sincronização: leitura do Protheus,
 * gravação no portal, status, custo meta), para mostrar na tela onde o tempo está indo.
 */
final class Cronometro
{
    /** @var array<string, float> */
    private static array $tempos = [];

    public static function zerar(): void
    {
        self::$tempos = [];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $tarefa
     * @return T
     */
    public static function medir(string $parte, callable $tarefa): mixed
    {
        $inicio = microtime(true);
        try {
            return $tarefa();
        } finally {
            self::$tempos[$parte] = (self::$tempos[$parte] ?? 0) + (microtime(true) - $inicio);
        }
    }

    /** @return array<string, float> parte => segundos (1 casa), da maior para a menor */
    public static function resumo(): array
    {
        $tempos = array_map(fn ($s) => round($s, 1), self::$tempos);
        arsort($tempos);

        return $tempos;
    }
}
