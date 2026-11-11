<?php

namespace App\Services\Manutencao;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Horas da mão de obra da manutenção pela JORNADA do mantenedor (no fuso local, config oee.fuso_exibicao):
 * - dividir(): separa um apontamento em minutos NORMAIS (dentro da jornada, num dia de trabalho) e EXTRAS (o resto:
 *   antes/depois da jornada ou em dia sem jornada);
 * - minutosDisponiveis(): horas que o colaborador fica na fábrica no período (jornada − intervalo, nos dias de trabalho),
 *   base do aproveitamento "apontado ÷ disponível" (meta > 90%).
 * Jornada que termina antes de começar (ex.: 22:00–06:00) vai até o dia seguinte. Dias: ISO 1 = segunda … 7 = domingo.
 */
final class HorasManutencao
{
    public function __construct(
        public readonly string $jornadaInicio = '07:00',
        public readonly string $jornadaFim = '17:00',
        public readonly int $intervaloMin = 60,
        public readonly string $diasSemana = '12345',
    ) {}

    /** Jornada de um mantenedor (qualquer objeto com jornada_inicio, jornada_fim, intervalo_min e dias_semana). */
    public static function de(object $mantenedor): self
    {
        return new self(
            (string) ($mantenedor->jornada_inicio ?: '07:00'),
            (string) ($mantenedor->jornada_fim ?: '17:00'),
            (int) ($mantenedor->intervalo_min ?? 60),
            (string) ($mantenedor->dias_semana ?? '12345'),
        );
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
            [$janelaIni, $janelaFim] = $this->janela($dia);
            if ($janelaIni === null) {
                continue;
            }
            $a = max($ini->getTimestamp(), $janelaIni->getTimestamp());
            $b = min($fi->getTimestamp(), $janelaFim->getTimestamp());
            if ($b > $a) {
                $dentro += $b - $a;
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
            [$janelaIni, $janelaFim] = $this->janela($dia);
            if ($janelaIni !== null) {
                $minutos += max(0, intdiv($janelaFim->getTimestamp() - $janelaIni->getTimestamp(), 60) - $this->intervaloMin);
            }
        }

        return $minutos;
    }

    /** Minutos de jornada num dia de trabalho (sem o intervalo). */
    public function minutosPorDia(): int
    {
        [$a, $b] = $this->janela(CarbonImmutable::parse('2026-01-05', self::fuso()), true);

        return max(0, intdiv($b->getTimestamp() - $a->getTimestamp(), 60) - $this->intervaloMin);
    }

    /** @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} */
    private function janela(CarbonImmutable $dia, bool $qualquerDia = false): array
    {
        if (! $qualquerDia && ! str_contains($this->diasSemana, (string) $dia->isoWeekday())) {
            return [null, null];
        }
        $ini = $dia->setTimeFromTimeString($this->jornadaInicio);
        $fim = $dia->setTimeFromTimeString($this->jornadaFim);
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
