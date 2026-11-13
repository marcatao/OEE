<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Jornada por dia da semana (máquinas e mantenedores): cada dia ISO (1 = segunda … 7 = domingo) com o horário em que
 * começa e termina (HH:MM, fuso local; fim antes do início = vira o dia). Gravada em `horarios_semana` (JSON
 * {"1":["07:00","17:00"],…}); as colunas antigas `dias_semana`, `jornada_inicio` e `jornada_fim` continuam preenchidas
 * (dias marcados e o horário do primeiro dia) e são a jornada de quem ainda não tem `horarios_semana`.
 */
final class JornadaSemanal
{
    public const DIAS = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];

    public const CURTOS = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];

    /**
     * Horários gravados no cadastro.
     *
     * @return array<int, array{0: string, 1: string}> dia ISO => [início, fim], só os dias de trabalho, em ordem
     */
    public static function de(object $registro): array
    {
        $json = $registro->horarios_semana ?? null;
        if (is_string($json) && $json !== '' && is_array($lido = json_decode($json, true))) {
            $horarios = [];
            foreach ($lido as $dia => $h) {
                if (isset(self::DIAS[(int) $dia], $h[0], $h[1])) {
                    $horarios[(int) $dia] = [(string) $h[0], (string) $h[1]];
                }
            }
            ksort($horarios);

            return $horarios;
        }
        $ini = (string) ($registro->jornada_inicio ?? '');
        $fim = (string) ($registro->jornada_fim ?? '');
        if ($ini === '' || $fim === '') {
            return [];
        }
        $horarios = [];
        foreach (str_split((string) ($registro->dias_semana ?? '')) as $dia) {
            if (isset(self::DIAS[(int) $dia])) {
                $horarios[(int) $dia] = [$ini, $fim];
            }
        }
        ksort($horarios);

        return $horarios;
    }

    /** Regras de validação dos campos do formulário (dias[] e horario[N][inicio|fim]). */
    public static function regras(): array
    {
        return [
            'dias' => ['nullable', 'array'], 'dias.*' => ['integer', 'between:1,7'],
            'horario' => ['nullable', 'array'],
            'horario.*.inicio' => ['nullable', 'date_format:H:i'],
            'horario.*.fim' => ['nullable', 'date_format:H:i'],
        ];
    }

    /**
     * Lê o formulário: os dias marcados, cada um com o seu horário (horario[N][inicio|fim]). Dia sem horário próprio
     * usa jornada_inicio/jornada_fim, se vierem; senão é erro.
     *
     * @return array<int, array{0: string, 1: string}>
     *
     * @throws ValidationException
     */
    public static function doFormulario(array $dados): array
    {
        $horarios = [];
        $erros = [];
        foreach (array_unique(array_map('intval', $dados['dias'] ?? [])) as $dia) {
            $ini = $dados['horario'][$dia]['inicio'] ?? $dados['jornada_inicio'] ?? null;
            $fim = $dados['horario'][$dia]['fim'] ?? $dados['jornada_fim'] ?? null;
            if (! $ini || ! $fim) {
                $erros["horario.{$dia}"] = 'Informe o horário em que começa e termina na '.mb_strtolower(self::DIAS[$dia]).'.';

                continue;
            }
            if ($ini === $fim) {
                $erros["horario.{$dia}"] = self::DIAS[$dia].': o horário de início e o de fim não podem ser iguais.';

                continue;
            }
            $horarios[$dia] = [$ini, $fim];
        }
        if ($erros) {
            throw ValidationException::withMessages($erros);
        }
        ksort($horarios);

        return $horarios;
    }

    /**
     * Colunas a gravar no cadastro.
     *
     * @param  array<int, array{0: string, 1: string}>  $horarios
     * @return array{horarios_semana: ?string, dias_semana: ?string, jornada_inicio: ?string, jornada_fim: ?string}
     */
    public static function colunas(array $horarios): array
    {
        $primeiro = $horarios ? reset($horarios) : [null, null];

        return [
            'horarios_semana' => $horarios ? json_encode(array_combine(array_map('strval', array_keys($horarios)), array_values($horarios))) : null,
            'dias_semana' => $horarios ? implode('', array_keys($horarios)) : null,
            'jornada_inicio' => $primeiro[0],
            'jornada_fim' => $primeiro[1],
        ];
    }

    /**
     * Resumo para listas: dias seguidos com o mesmo horário ficam juntos ("Seg–Sex 07:00–17:00 · Sáb 07:00–11:00").
     *
     * @param  array<int, array{0: string, 1: string}>  $horarios
     */
    public static function texto(array $horarios): string
    {
        $grupos = [];
        foreach ($horarios as $dia => $h) {
            $ultimo = array_key_last($grupos);
            if ($ultimo !== null && $grupos[$ultimo]['h'] === $h && $grupos[$ultimo]['ate'] === $dia - 1) {
                $grupos[$ultimo]['ate'] = $dia;
            } else {
                $grupos[] = ['de' => $dia, 'ate' => $dia, 'h' => $h];
            }
        }

        return implode(' · ', array_map(fn ($g) => self::CURTOS[$g['de']].($g['ate'] !== $g['de'] ? '–'.self::CURTOS[$g['ate']] : '')
            .' '.$g['h'][0].'–'.$g['h'][1], $grupos)) ?: 'sem jornada';
    }
}
