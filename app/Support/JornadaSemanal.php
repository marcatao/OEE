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

    // ---------------------------------------------------------------- turnos (máquinas: até 3 por dia, 25/11/2026)

    public const MAX_TURNOS = 3;

    /**
     * Turnos de cada dia de trabalho: turnos_semana (JSON {"1":[["05:00","13:00"],["13:00","21:00"]],…}); sem ela, o
     * horário antigo do dia vira o turno 1. Cada turno pertence ao dia em que começa; fim antes do início = vira o dia.
     *
     * @return array<int, array<int, array{0: string, 1: string}>> dia ISO => [nº do turno => [início, fim]]
     */
    public static function turnos(object $registro): array
    {
        $json = $registro->turnos_semana ?? null;
        if (is_string($json) && $json !== '' && is_array($lido = json_decode($json, true))) {
            $dias = [];
            foreach ($lido as $dia => $turnos) {
                if (! isset(self::DIAS[(int) $dia]) || ! is_array($turnos)) {
                    continue;
                }
                foreach ($turnos as $n => $t) {
                    $n = (int) $n;
                    if ($n >= 1 && $n <= self::MAX_TURNOS && isset($t[0], $t[1]) && $t[0] !== $t[1]) {
                        $dias[(int) $dia][$n] = [(string) $t[0], (string) $t[1]];
                    }
                }
                if (isset($dias[(int) $dia])) {
                    ksort($dias[(int) $dia]);
                }
            }
            ksort($dias);

            return $dias;
        }

        return array_map(fn ($h) => [1 => $h], self::de($registro));
    }

    /** Duração do turno em minutos (fim antes do início = vira o dia). */
    public static function minutosDoTurno(array $turno): int
    {
        [$a, $b] = array_map(fn ($h) => (int) substr($h, 0, 2) * 60 + (int) substr($h, 3, 2), $turno);

        return $b > $a ? $b - $a : $b + 1440 - $a;
    }

    /** Horas disponíveis do dia = soma dos turnos, em minutos. */
    public static function minutosDoDia(array $turnosDoDia): int
    {
        return array_sum(array_map(fn ($t) => self::minutosDoTurno($t), $turnosDoDia));
    }

    /**
     * Lê o formulário de turnos: dias[] marcados e turno[dia][n][inicio|fim] (n = 1..3; o turno 1 é obrigatório nos dias
     * marcados, os outros opcionais). Recusa turno com início = fim, turno 2/3 sem o anterior e turnos sobrepostos.
     *
     * @return array<int, array<int, array{0: string, 1: string}>>
     *
     * @throws ValidationException
     */
    public static function turnosDoFormulario(array $dados): array
    {
        $dias = [];
        $erros = [];
        foreach (array_unique(array_map('intval', $dados['dias'] ?? [])) as $dia) {
            if (! isset(self::DIAS[$dia])) {
                continue;
            }
            $nome = mb_strtolower(self::DIAS[$dia]);
            $turnos = [];
            for ($n = 1; $n <= self::MAX_TURNOS; $n++) {
                $ini = $dados['turno'][$dia][$n]['inicio'] ?? null;
                $fim = $dados['turno'][$dia][$n]['fim'] ?? null;
                if (! $ini && ! $fim) {
                    if ($n === 1) {
                        $erros["turno.{$dia}"] = "Informe o turno 1 (começa e termina) na {$nome}.";
                    }

                    continue;
                }
                if (! $ini || ! $fim) {
                    $erros["turno.{$dia}"] = "Turno {$n} da {$nome}: informe o horário em que começa e o que termina.";

                    continue;
                }
                if ($ini === $fim) {
                    $erros["turno.{$dia}"] = "Turno {$n} da {$nome}: o início e o fim não podem ser iguais.";

                    continue;
                }
                if ($n > 1 && ! isset($turnos[$n - 1])) {
                    $erros["turno.{$dia}"] = "Turno {$n} da {$nome}: preencha antes o turno ".($n - 1).'.';

                    continue;
                }
                $turnos[$n] = [$ini, $fim];
            }
            if ($turnos && ! isset($erros["turno.{$dia}"]) && ($sobreposto = self::sobreposicao($turnos))) {
                $erros["turno.{$dia}"] = ucfirst($nome).": {$sobreposto}";
            }
            if ($turnos) {
                $dias[$dia] = $turnos;
            }
        }
        if ($erros) {
            throw ValidationException::withMessages($erros);
        }
        ksort($dias);

        return $dias;
    }

    /** Mensagem se dois turnos do dia se sobrepõem (cada turno começa depois do início do anterior), ou null. */
    private static function sobreposicao(array $turnos): ?string
    {
        $faixas = [];
        $base = null;
        foreach ($turnos as $n => $t) {
            $a = (int) substr($t[0], 0, 2) * 60 + (int) substr($t[0], 3, 2);
            $base ??= $a;
            $a = $a < $base ? $a + 1440 : $a;              // turno depois da meia-noite (ex.: turno 3 às 00:30)
            $faixas[$n] = [$a, $a + self::minutosDoTurno($t)];
        }
        $ns = array_keys($faixas);
        foreach ($ns as $i => $n) {
            foreach (array_slice($ns, $i + 1) as $m) {
                if ($faixas[$n][0] < $faixas[$m][1] && $faixas[$m][0] < $faixas[$n][1]) {
                    return "o turno {$n} e o turno {$m} se sobrepõem.";
                }
            }
        }
        if (max(array_column($faixas, 1)) - min(array_column($faixas, 0)) > 1440) {
            return 'os turnos passam de 24 horas.';
        }

        return null;
    }

    /**
     * Colunas a gravar a partir dos turnos: turnos_semana + o envelope de cada dia em horarios_semana (início do turno 1
     * ao fim do último; dia de 24 h = termina 1 minuto antes de começar) e as colunas antigas.
     */
    public static function colunasDosTurnos(array $turnos): array
    {
        $envelope = [];
        foreach ($turnos as $dia => $doDia) {
            $primeiro = reset($doDia);
            $ultimo = end($doDia);
            $fim = $ultimo[1];
            if (self::minutosDoDia($doDia) >= 1440 || $fim === $primeiro[0]) {
                $m = ((int) substr($primeiro[0], 0, 2) * 60 + (int) substr($primeiro[0], 3, 2) + 1439) % 1440;
                $fim = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
            }
            $envelope[$dia] = [$primeiro[0], $fim];
        }

        return self::colunas($envelope) + [
            'turnos_semana' => $turnos ? json_encode(array_combine(array_map('strval', array_keys($turnos)),
                array_map(fn ($d) => array_combine(array_map('strval', array_keys($d)), array_values($d)), array_values($turnos)))) : null,
        ];
    }

    /** "Seg–Sex T1 05:00–13:00, T2 13:00–21:00 · Sáb T1 05:00–11:00" (dias seguidos com os mesmos turnos juntos). */
    public static function textoTurnos(array $turnos): string
    {
        $grupos = [];
        foreach ($turnos as $dia => $t) {
            $ultimo = array_key_last($grupos);
            if ($ultimo !== null && $grupos[$ultimo]['t'] === $t && $grupos[$ultimo]['ate'] === $dia - 1) {
                $grupos[$ultimo]['ate'] = $dia;
            } else {
                $grupos[] = ['de' => $dia, 'ate' => $dia, 't' => $t];
            }
        }
        $fmt = fn ($t) => count($t) === 1 ? reset($t)[0].'–'.reset($t)[1]
            : implode(', ', array_map(fn ($n, $h) => "T{$n} {$h[0]}–{$h[1]}", array_keys($t), $t));

        return implode(' · ', array_map(fn ($g) => self::CURTOS[$g['de']].($g['ate'] !== $g['de'] ? '–'.self::CURTOS[$g['ate']] : '')
            .' '.$fmt($g['t']), $grupos)) ?: 'sem jornada';
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
