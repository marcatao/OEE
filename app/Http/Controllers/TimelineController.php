<?php

namespace App\Http\Controllers;

use App\Models\Legenda;
use App\Models\Maquina;
use App\Models\Turno;
use App\Services\Timeline;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Dashboards > Timeline: estado de cada máquina ao longo do dia (ou de um turno), pintado com a cor da legenda.
 */
class TimelineController extends Controller
{
    public function __invoke(Request $request, Timeline $timeline): View
    {
        $fuso = config('oee.fuso_exibicao');
        [$h, $m] = array_map('intval', explode(':', (string) config('oee.timeline_inicio_dia', '06:00')) + [0, 0]);

        // "Hoje" é o dia de produção atual: antes da hora de início do dia, ainda vale o dia anterior.
        $agora = CarbonImmutable::now($fuso);
        $hoje = $agora->lt($agora->setTime($h, $m)) ? $agora->subDay()->startOfDay() : $agora->startOfDay();
        $data = $hoje;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('data'))) {
            $data = CarbonImmutable::createFromFormat('!Y-m-d', $request->query('data'), $fuso) ?: $hoje;
        }

        $turnos = Turno::where('ativo', true)->orderBy('inicio')->get();
        $turno = $turnos->firstWhere('id', (int) $request->query('turno'));
        $inicioDia = $data->setTime($h, $m);
        if ($turno) {
            $inicio = $data->setTimeFromTimeString((string) $turno->inicio);
            if ($inicio->lt($inicioDia)) {
                $inicio = $inicio->addDay(); // turno da madrugada pertence ao fim do dia de produção
            }
            $fim = $inicio->setTimeFromTimeString((string) $turno->fim);
            if ($fim->lte($inicio)) {
                $fim = $fim->addDay(); // turno que vira a noite
            }
        } else {
            $inicio = $inicioDia;
            $fim = $inicioDia->addDay();
        }

        $maquinas = Maquina::with('linha.planta')->where('ativo', true)->orderBy('nome')->get();
        $dados = $timeline->montar($maquinas, $inicio->utc(), $fim->utc());
        $agoraTs = now()->getTimestamp();

        return view('dashboard.timeline', [
            'data' => $data,
            'hoje' => $hoje,
            'turnos' => $turnos,
            'turno' => $turno,
            'inicio' => $inicio,
            'fim' => $fim,
            'maquinas' => $maquinas,
            'linhas' => $dados['linhas'],
            'totais' => $dados['totais'],
            'disponibilidade' => $dados['disponibilidade'],
            'marcas' => $timeline->marcas($inicio->utc(), $fim->utc()),
            'agora' => $agoraTs > $inicio->getTimestamp() && $agoraTs < $fim->getTimestamp()
                ? round(($agoraTs - $inicio->getTimestamp()) / ($fim->getTimestamp() - $inicio->getTimestamp()) * 100, 3) : null,
            'legendas' => Legenda::where('ativo', true)->orderBy('ordem')->orderBy('codigo')->get(),
        ]);
    }
}
