<?php

namespace App\Http\Controllers;

use App\Models\Apontamento;
use App\Models\Maquina;
use App\Models\Parada;
use App\Services\IndicadoresOee;
use App\Services\Periodo;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Produção > Dashboard por máquina: visão geral (todas as máquinas) e painel de uma máquina.
 */
class DashboardController extends Controller
{
    public function index(Request $request, IndicadoresOee $indicadores): View
    {
        $periodo = Periodo::de($request->query('periodo'));
        $maquinas = Maquina::with('linha.planta')->where('ativo', true)->orderBy('nome')->get();

        return view('dashboard.maquinas', [
            'periodo' => $periodo,
            'maquinas' => $maquinas,
            'indicadores' => $maquinas->isEmpty() ? [] : $indicadores->porMaquina($periodo->inicio, $periodo->fim, $maquinas->modelKeys()),
            'paradasAbertas' => Parada::whereNull('fim')->pluck('maquina_id')->flip(),
        ]);
    }

    public function maquina(Request $request, Maquina $maquina, IndicadoresOee $indicadores): View
    {
        $periodo = Periodo::de($request->query('periodo'));
        $maquina->load(['linha.planta', 'dispositivos' => fn ($q) => $q->orderBy('nome')]);

        return view('dashboard.maquina', [
            'periodo' => $periodo,
            'maquina' => $maquina,
            'maquinas' => Maquina::where('ativo', true)->orderBy('nome')->get(['id', 'codigo', 'nome']),
            'ind' => $indicadores->daMaquina($maquina->id, $periodo->inicio, $periodo->fim),
            'apontamentos' => Apontamento::where('maquina_id', $maquina->id)
                ->where('inicio', '>=', $periodo->inicio)->where('inicio', '<', $periodo->fim)
                ->orderByDesc('inicio')->limit(15)->get(),
            'paradaAberta' => Parada::with('motivo')->where('maquina_id', $maquina->id)->whereNull('fim')->latest('inicio')->first(),
        ]);
    }
}
