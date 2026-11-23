<?php

namespace App\Http\Controllers;

use App\Models\Apontamento;
use App\Models\ApontamentoEstado;
use App\Models\Maquina;
use App\Models\Parada;
use App\Services\HorarioApontamento;
use App\Services\IndicadoresOee;
use App\Services\PanoramaMaquinas;
use App\Services\Periodo;
use App\Services\ProducaoPorHora;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Produção > Dashboard por máquina: panorama de todas as máquinas ativas com IoT ativo e painel de uma máquina.
 *
 * O panorama vem dos APONTAMENTOS REAIS (os da Timeline: situação, OP, molde, tempos e disponibilidade). O OEE
 * completo (performance e qualidade) usa a contagem de peças (tabelas antigas apontamentos/paradas, que virão do IoT)
 * e só aparece quando houver esses dados.
 */
class DashboardController extends Controller
{
    public function index(Request $request, IndicadoresOee $indicadores, PanoramaMaquinas $panorama): View
    {
        $periodo = Periodo::de($request->query('periodo'));
        // Só máquinas ativas e com IoT ativo (canal com dispositivo ativo).
        $maquinas = Maquina::with('linha.planta')->where('ativo', true)->comIotAtivo()->orderBy('nome')->get();

        return view('dashboard.maquinas', [
            'periodo' => $periodo,
            'maquinas' => $maquinas,
            'panorama' => $panorama->montar($maquinas, $periodo->inicio, $periodo->fim),
            'indicadores' => $maquinas->isEmpty() ? [] : $indicadores->porMaquina($periodo->inicio, $periodo->fim, $maquinas->modelKeys()),
        ]);
    }

    public function maquina(Request $request, Maquina $maquina, IndicadoresOee $indicadores, PanoramaMaquinas $panorama, ProducaoPorHora $producaoHora): View
    {
        $periodo = Periodo::de($request->query('periodo'));
        HorarioApontamento::fecharAbertosAnteriores((int) $maquina->id); // só o mais recente fica em aberto
        $maquina->load(['linha.planta', 'dispositivos' => fn ($q) => $q->orderBy('nome')]);

        return view('dashboard.maquina', [
            'periodo' => $periodo,
            'maquina' => $maquina,
            'maquinas' => Maquina::where(fn ($q) => $q->where('ativo', true)->comIotAtivo())->orWhere('id', $maquina->id)
                ->orderBy('nome')->get(['id', 'codigo', 'nome']),
            'panorama' => $panorama->montar(new Collection([$maquina]), $periodo->inicio, $periodo->fim),
            // Produção por hora desta máquina (o mesmo cartão de Dashboards › Produção por hora): o turno de hoje ou,
            // num período que já terminou, o do último dia dele.
            'diaProducao' => $dia = ($periodo->fim->subSecond()->lessThan(CarbonImmutable::now()) ? $periodo->fim->subSecond() : CarbonImmutable::now())
                ->setTimezone(HorarioApontamento::fuso())->startOfDay(),
            'producaoHora' => $producaoHora->montar(new Collection([$maquina->loadMissing('ordemProducao')]), $dia)['maquinas'][0] ?? null,
            // Apontamentos reais (Timeline) que tocam o período, do mais recente para o mais antigo.
            'apontamentosReais' => ApontamentoEstado::with(['legenda', 'ordemProducao'])->where('maquina_id', $maquina->id)
                ->tocando($periodo->inicio, $periodo->fim)
                ->orderByDesc('inicio')->limit(50)->get(),
            'ind' => $indicadores->daMaquina($maquina->id, $periodo->inicio, $periodo->fim),
            'apontamentos' => Apontamento::where('maquina_id', $maquina->id)
                ->where('inicio', '>=', $periodo->inicio)->where('inicio', '<', $periodo->fim)
                ->orderByDesc('inicio')->limit(15)->get(),
            'paradaAberta' => Parada::with('motivo')->where('maquina_id', $maquina->id)->whereNull('fim')->latest('inicio')->first(),
        ]);
    }
}
