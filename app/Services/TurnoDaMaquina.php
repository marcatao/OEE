<?php

namespace App\Services;

use App\Models\Maquina;
use App\Services\Calendario\DiasUteis;
use App\Services\Filtros\FiltroTimeline;
use App\Support\JornadaSemanal;
use Carbon\CarbonImmutable;

/**
 * Turnos da máquina num dia (tela da máquina no app, Produção por hora e OEE do app): até 3 turnos por dia
 * (JornadaSemanal::turnos; fim antes do início = termina no dia seguinte), só se a máquina trabalha no dia (DiasUteis).
 * O período do dia vai do início do turno 1 ao fim do último; os intervalos entre turnos não são horas disponíveis
 * (sem meta). Sem jornada no dia (ou fora do calendário), o padrão da Timeline (01:00–23:59).
 */
class TurnoDaMaquina
{
    public function __construct(private DiasUteis $diasUteis) {}

    /**
     * @param  CarbonImmutable|null  $dia  dia (no fuso local); null = hoje
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string} início, fim (exclusivo) e rótulo
     */
    public function periodo(Maquina $maquina, ?CarbonImmutable $dia = null): array
    {
        $d = $this->doDia($maquina, $dia);

        return [$d['de'], $d['ate'], $d['rotulo']];
    }

    /**
     * Os turnos do dia (o mesmo dia do período): cada um com o número, início e fim (exclusivo). Vazio sem jornada.
     *
     * @return list<array{numero: int, de: CarbonImmutable, ate: CarbonImmutable}>
     */
    public function intervalos(Maquina $maquina, ?CarbonImmutable $dia = null): array
    {
        return $this->doDia($maquina, $dia)['turnos'];
    }

    /**
     * Turno da máquina num instante (perdas pelo app: o turno do horário do lançamento): 1, 2 ou 3; null fora de turno.
     */
    public function turnoEm(Maquina $maquina, CarbonImmutable $momento): ?int
    {
        $fuso = HorarioApontamento::fuso();
        $local = $momento->setTimezone($fuso);
        foreach ([$local->startOfDay()->subDay(), $local->startOfDay()] as $dia) {
            foreach ($this->turnosDe($maquina, $dia) as $t) {
                if ($momento->greaterThanOrEqualTo($t['de']) && $momento->lessThan($t['ate'])) {
                    return $t['numero'];
                }
            }
        }

        return null;
    }

    /** @return array{de: CarbonImmutable, ate: CarbonImmutable, rotulo: string, turnos: list<array>} */
    private function doDia(Maquina $maquina, ?CarbonImmutable $dia): array
    {
        $fuso = HorarioApontamento::fuso();
        $agora = CarbonImmutable::now($fuso);
        $dia = $dia ? $dia->setTimezone($fuso)->startOfDay() : $agora->startOfDay();
        $ehHoje = $dia->isSameDay($agora);

        // Hoje: o último turno de ontem que vira o dia ainda em andamento (ex.: turno 3 22:00–06:00, agora 02:00) é o que vale.
        if ($ehHoje) {
            $ontem = $dia->subDay();
            $deOntem = $this->turnosDe($maquina, $ontem);
            if ($deOntem && $agora->lessThan(end($deOntem)['ate']) && end($deOntem)['ate']->greaterThan($dia)) {
                return $this->montar($deOntem, 'Turno de '.$ontem->format('d/m').' · ', $maquina, $ontem);
            }
        }

        $turnos = $this->turnosDe($maquina, $dia);
        if ($turnos) {
            return $this->montar($turnos, ($ehHoje ? 'Turno de hoje, ' : 'Turno de ').$dia->format('d/m').' · ', $maquina, $dia);
        }

        [$de, $ate] = FiltroTimeline::periodoDoDia($dia, FiltroTimeline::HORA_DE_PADRAO, FiltroTimeline::HORA_ATE_PADRAO);
        $temJornada = isset(JornadaSemanal::turnos($maquina)[$dia->dayOfWeekIso]);

        return ['de' => $de, 'ate' => $ate->addMinute(), 'turnos' => [],
            'rotulo' => ($ehHoje ? 'Hoje, ' : 'Dia ').$dia->format('d/m').' · '.$de->format('H:i').' às '.$ate->format('H:i')
                .($temJornada ? ' (fora do calendário de trabalho)' : '')];
    }

    private function montar(array $turnos, string $prefixo, Maquina $maquina, CarbonImmutable $dia): array
    {
        $doDia = JornadaSemanal::turnos($maquina)[$dia->dayOfWeekIso] ?? [];
        $texto = count($doDia) === 1
            ? reset($doDia)[0].' às '.reset($doDia)[1]
            : implode(' · ', array_map(fn ($n, $h) => "T{$n} {$h[0]}–{$h[1]}", array_keys($doDia), $doDia));

        // (o período termina 1 min depois do fim do último turno, como antes: a hora do fim, sem tempo de turno, não vira barra)
        return ['de' => $turnos[0]['de'], 'ate' => end($turnos)['ate']->addMinute(), 'turnos' => $turnos, 'rotulo' => $prefixo.$texto];
    }

    /**
     * Turnos que COMEÇAM no dia (se a máquina trabalha no dia pelo calendário), em ordem.
     *
     * @return list<array{numero: int, de: CarbonImmutable, ate: CarbonImmutable}>
     */
    private function turnosDe(Maquina $maquina, CarbonImmutable $dia): array
    {
        $doDia = JornadaSemanal::turnos($maquina)[$dia->dayOfWeekIso] ?? [];
        if (! $doDia || ! $this->diasUteis->trabalha($maquina, $dia->format('Y-m-d'))) {
            return [];
        }
        $saida = [];
        $base = null;
        foreach ($doDia as $n => [$ini, $fim]) {
            [$de, $ate] = FiltroTimeline::periodoDoDia($dia, $ini, $fim);
            // turno que começa depois da meia-noite (ex.: turno 3 às 00:30 depois do turno 2 até 23:30)
            if ($base !== null && $de->lessThan($base)) {
                [$de, $ate] = [$de->addDay(), $ate->addDay()];
            }
            $base ??= $de;
            $saida[] = ['numero' => (int) $n, 'de' => $de, 'ate' => $ate];
        }
        usort($saida, fn ($a, $b) => $a['de'] <=> $b['de']);

        return $saida;
    }
}
