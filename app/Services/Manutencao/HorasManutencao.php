<?php

namespace App\Services\Manutencao;

use App\Models\CalendarioDia;
use App\Support\JornadaSemanal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Horas da mão de obra da manutenção pela JORNADA do mantenedor (no fuso local, config oee.fuso_exibicao):
 * - dividir(): separa um apontamento em minutos NORMAIS (dentro da jornada — ou de qualquer um dos até 3 turnos do dia —
 *   num dia de trabalho) e EXTRAS (o resto: antes/depois, entre turnos ou em dia sem jornada);
 * - minutosDisponiveis(): horas que o colaborador fica na fábrica no período (jornada − intervalo, nos dias de trabalho),
 *   base do aproveitamento "apontado ÷ disponível" (meta > 90%).
 * Jornada que termina antes de começar (ex.: 22:00–06:00) vai até o dia seguinte. Dias: ISO 1 = segunda … 7 = domingo.
 * Com calendário (Administração › Calendários): feriado, ponte e folga não têm jornada; compensação tem.
 */
final class HorasManutencao
{
    public function __construct(
        public readonly string $jornadaInicio = '07:00',
        public readonly string $jornadaFim = '17:00',
        public readonly int $intervaloMin = 60,
        public readonly string $diasSemana = '12345',
        /** Horário por dia da semana (dia ISO => [início, fim]); sem ele, jornadaInicio/jornadaFim nos diasSemana. */
        public readonly array $horarios = [],
        /** Exceções do calendário do mantenedor: 'Y-m-d' => true (compensação/útil) | false (feriado, ponte, folga). */
        public readonly array $excecoes = [],
        /**
         * Até 3 turnos por dia (dia ISO => [nº => [início, fim]], JornadaSemanal::turnos): quando vem, vale mais que os
         * horários — dentro de QUALQUER turno é hora normal; o intervalo entre turnos é extra e não é hora disponível.
         */
        public readonly array $turnos = [],
    ) {}

    /** Jornada de um mantenedor (jornada_inicio, jornada_fim, intervalo_min, dias_semana, o horário e os turnos de cada dia). */
    public static function de(object $mantenedor): self
    {
        $temTurnos = is_string($mantenedor->turnos_semana ?? null) && $mantenedor->turnos_semana !== '';

        return new self(
            (string) ($mantenedor->jornada_inicio ?: '07:00'),
            (string) ($mantenedor->jornada_fim ?: '17:00'),
            (int) ($mantenedor->intervalo_min ?? 60),
            (string) ($mantenedor->dias_semana ?? '12345'),
            JornadaSemanal::de($mantenedor),
            self::excecoesDoCalendario($mantenedor->calendario_id ?? null),
            $temTurnos ? JornadaSemanal::turnos($mantenedor) : [],
        );
    }

    /** Feriados, pontes, folgas (false) e compensações (true) do calendário, por data. @return array<string, bool> */
    public static function excecoesDoCalendario(?int $calendarioId): array
    {
        if (! $calendarioId) {
            return [];
        }

        return CalendarioDia::where('calendario_id', $calendarioId)->get()
            ->mapWithKeys(fn ($d) => [$d->data->format('Y-m-d') => $d->util()])->all();
    }

    public static function fuso(): string
    {
        return (string) config('oee.fuso_exibicao', 'America/Sao_Paulo');
    }

    /**
     * Divide o intervalo em minutos normais e extras.
     *
     * @return array{normais: int, extras: int}
     */
    public function dividir(CarbonInterface $inicio, CarbonInterface $fim): array
    {
        $ini = CarbonImmutable::instance($inicio)->setTimezone(self::fuso());
        $fi = CarbonImmutable::instance($fim)->setTimezone(self::fuso());
        if ($fi <= $ini) {
            return ['normais' => 0, 'extras' => 0];
        }
        $total = (int) round(($fi->getTimestamp() - $ini->getTimestamp()) / 60);

        $dentro = 0;
        // Começa no dia anterior para pegar a jornada que vira a noite.
        for ($dia = $ini->subDay()->startOfDay(); $dia <= $fi; $dia = $dia->addDay()) {
            foreach ($this->janelas($dia) as [$janelaIni, $janelaFim]) {
                $a = max($ini->getTimestamp(), $janelaIni->getTimestamp());
                $b = min($fi->getTimestamp(), $janelaFim->getTimestamp());
                if ($b > $a) {
                    $dentro += $b - $a;
                }
            }
        }
        $normais = min($total, (int) round($dentro / 60));

        return ['normais' => $normais, 'extras' => $total - $normais];
    }

    /** Minutos disponíveis entre dois dias (inclusive), no fuso local. */
    public function minutosDisponiveis(CarbonInterface $de, CarbonInterface $ate): int
    {
        $dia = CarbonImmutable::parse($de->format('Y-m-d'), self::fuso());
        $ultimo = CarbonImmutable::parse($ate->format('Y-m-d'), self::fuso());
        $minutos = 0;
        for (; $dia <= $ultimo; $dia = $dia->addDay()) {
            if ($janelas = $this->janelas($dia)) {
                $minutos += max(0, $this->minutosDas($janelas) - $this->intervaloMin);
            }
        }

        return $minutos;
    }

    /** Minutos de jornada num dia de trabalho (sem o intervalo); com horários diferentes por dia, a média dos dias. */
    public function minutosPorDia(): int
    {
        if ($this->turnos) {
            $total = 0;
            foreach ($this->turnos as $doDia) {
                $total += max(0, JornadaSemanal::minutosDoDia($doDia) - $this->intervaloMin);
            }

            return intdiv($total, count($this->turnos));
        }
        if (! $this->horarios) {
            [$a, $b] = $this->janela(CarbonImmutable::parse('2026-01-05', self::fuso()), true);

            return max(0, intdiv($b->getTimestamp() - $a->getTimestamp(), 60) - $this->intervaloMin);
        }
        $total = 0;
        foreach (array_keys($this->horarios) as $dia) {
            // 2026-01-05 é uma segunda-feira: + (dia − 1) cai no dia da semana certo.
            [$a, $b] = $this->janela(CarbonImmutable::parse('2026-01-05', self::fuso())->addDays($dia - 1));
            $total += max(0, intdiv($b->getTimestamp() - $a->getTimestamp(), 60) - $this->intervaloMin);
        }

        return intdiv($total, count($this->horarios));
    }

    /**
     * Faixas de trabalho do dia (cada turno; sem turnos, a jornada única do dia). Feriado/ponte/folga do calendário: nenhuma;
     * compensação em dia sem jornada: os turnos do primeiro dia de trabalho. Turno que começa antes do turno 1 é do dia
     * seguinte (ex.: turno 3 às 00:30); fim antes do início = vira o dia.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function janelas(CarbonImmutable $dia): array
    {
        if (! $this->turnos) {
            [$a, $b] = $this->janela($dia);

            return $a === null ? [] : [[$a, $b]];
        }
        $excecao = $this->excecoes[$dia->format('Y-m-d')] ?? null;
        if ($excecao === false) {
            return [];
        }
        $doDia = $this->turnos[$dia->isoWeekday()] ?? ($excecao === true ? reset($this->turnos) : null);
        if (! $doDia) {
            return [];
        }
        $faixas = [];
        $base = null;
        foreach ($doDia as $t) {
            $ini = $dia->setTimeFromTimeString($t[0]);
            $base ??= $ini;
            if ($ini < $base) {
                $ini = $ini->addDay();
            }
            $faixas[] = [$ini, $ini->addMinutes(JornadaSemanal::minutosDoTurno($t))];
        }

        return $faixas;
    }

    /** Soma das faixas, em minutos. */
    private function minutosDas(array $janelas): int
    {
        return array_sum(array_map(fn ($j) => intdiv($j[1]->getTimestamp() - $j[0]->getTimestamp(), 60), $janelas));
    }

    /** Jornada única do dia (cadastro sem turnos). @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} */
    private function janela(CarbonImmutable $dia, bool $qualquerDia = false): array
    {
        // Calendário do mantenedor: feriado/ponte/folga não tem jornada (tudo é extra); compensação tem, mesmo no fim de semana.
        $excecao = $qualquerDia ? null : ($this->excecoes[$dia->format('Y-m-d')] ?? null);
        if ($excecao === false) {
            return [null, null];
        }
        if ($excecao === true && ! isset($this->horarios[$dia->isoWeekday()]) && ! str_contains($this->diasSemana, (string) $dia->isoWeekday())) {
            [$hIni, $hFim] = $this->horarios ? array_values($this->horarios)[0] : [$this->jornadaInicio, $this->jornadaFim];
            $ini = $dia->setTimeFromTimeString($hIni);
            $fim = $dia->setTimeFromTimeString($hFim);

            return [$ini, $fim <= $ini ? $fim->addDay() : $fim];
        }
        if ($this->horarios) {
            if (! isset($this->horarios[$dia->isoWeekday()])) {
                return [null, null];
            }
            [$hIni, $hFim] = $this->horarios[$dia->isoWeekday()];
        } elseif (! $qualquerDia && ! str_contains($this->diasSemana, (string) $dia->isoWeekday())) {
            return [null, null];
        } else {
            [$hIni, $hFim] = [$this->jornadaInicio, $this->jornadaFim];
        }
        $ini = $dia->setTimeFromTimeString($hIni);
        $fim = $dia->setTimeFromTimeString($hFim);
        if ($fim <= $ini) {
            $fim = $fim->addDay();
        }

        return [$ini, $fim];
    }

    /** 90 → "1:30". */
    public static function hhmm(int|float|null $minutos): string
    {
        $minutos = (int) round((float) $minutos);

        return intdiv($minutos, 60).':'.str_pad((string) ($minutos % 60), 2, '0', STR_PAD_LEFT);
    }

    /** 90 → "1,50" (o "décimo" da planilha da contabilidade: horas em decimal). */
    public static function decimal(int|float|null $minutos): string
    {
        return number_format(((float) $minutos) / 60, 2, ',', '.');
    }
}
