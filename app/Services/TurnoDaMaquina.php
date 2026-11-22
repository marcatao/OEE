<?php

namespace App\Services;

use App\Models\Maquina;
use App\Services\Calendario\DiasUteis;
use App\Services\Filtros\FiltroTimeline;
use App\Support\JornadaSemanal;
use Carbon\CarbonImmutable;

/**
 * Turno da máquina num dia (tela da máquina no app e Produção por hora no portal): horário de início e fim do dia da
 * semana (JornadaSemanal; fim antes do início = termina no dia seguinte), só se a máquina trabalha no dia
 * (DiasUteis). Sem jornada no dia (ou fora do calendário), o padrão da Timeline (01:00–23:59).
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
        $fuso = HorarioApontamento::fuso();
        $agora = CarbonImmutable::now($fuso);
        $dia = $dia ? $dia->setTimezone($fuso)->startOfDay() : $agora->startOfDay();
        $ehHoje = $dia->isSameDay($agora);
        $jornadas = JornadaSemanal::de($maquina);

        // Hoje: turno noturno de ontem ainda em andamento (ex.: seg 22:00–06:00, agora ter 02:00) é o que vale.
        if ($ehHoje) {
            $ontem = $dia->subDay();
            $jornadaOntem = $jornadas[$ontem->dayOfWeekIso] ?? null;
            if ($jornadaOntem && $jornadaOntem[1] < $jornadaOntem[0] && $this->diasUteis->trabalha($maquina, $ontem->format('Y-m-d'))) {
                [$de, $ate] = FiltroTimeline::periodoDoDia($ontem, $jornadaOntem[0], $jornadaOntem[1]);
                if ($agora->lessThan($ate->addMinute())) {
                    return [$de, $ate->addMinute(), 'Turno de '.$ontem->format('d/m').' · '.$jornadaOntem[0].' às '.$jornadaOntem[1]];
                }
            }
        }

        $jornada = $jornadas[$dia->dayOfWeekIso] ?? null;
        if ($jornada && $this->diasUteis->trabalha($maquina, $dia->format('Y-m-d'))) {
            [$de, $ate] = FiltroTimeline::periodoDoDia($dia, $jornada[0], $jornada[1]);

            return [$de, $ate->addMinute(), ($ehHoje ? 'Turno de hoje, ' : 'Turno de ').$dia->format('d/m').' · '.$jornada[0].' às '.$jornada[1]];
        }

        [$de, $ate] = FiltroTimeline::periodoDoDia($dia, FiltroTimeline::HORA_DE_PADRAO, FiltroTimeline::HORA_ATE_PADRAO);

        return [$de, $ate->addMinute(), ($ehHoje ? 'Hoje, ' : 'Dia ').$dia->format('d/m').' · '.$de->format('H:i')
            .' às '.$ate->format('H:i').($jornada ? ' (fora do calendário de trabalho)' : '')];
    }
}
