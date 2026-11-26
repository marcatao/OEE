<?php

namespace App\Http\Controllers;

use App\Models\Apontamento;
use App\Models\ApontamentoEstado;
use App\Models\Legenda;
use App\Models\Maquina;
use App\Models\Parada;
use App\Services\CiclosIot;
use App\Services\HorarioApontamento;
use App\Services\IndicadoresOee;
use App\Services\PanoramaMaquinas;
use App\Services\Periodo;
use App\Services\ProducaoPorHora;
use App\Services\Timeline;
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

    public function maquina(Request $request, Maquina $maquina, IndicadoresOee $indicadores, PanoramaMaquinas $panorama, ProducaoPorHora $producaoHora, CiclosIot $ciclosIot): View
    {
        $periodo = Periodo::de($request->query('periodo'));
        [$diasCiclos, $diaCiclos] = $this->diasDosCiclos($periodo, $request->query('ciclos_dia'));
        HorarioApontamento::fecharAbertosAnteriores((int) $maquina->id); // só o mais recente fica em aberto
        $maquina->load(['linha.planta', 'iot1Dispositivo', 'iot2Dispositivo', 'iot3Dispositivo']);

        return view('dashboard.maquina', [
            'periodo' => $periodo,
            'maquina' => $maquina,
            'maquinas' => Maquina::where(fn ($q) => $q->where('ativo', true)->comIotAtivo())->orWhere('id', $maquina->id)
                ->orderBy('nome')->get(['id', 'codigo', 'nome']),
            'panorama' => $dadosPanorama = $panorama->montar(new Collection([$maquina]), $periodo->inicio, $periodo->fim),
            // Timeline da máquina no período do filtro (todas as legendas, como a tela Timeline).
            'timelineMaquina' => [
                'blocos' => $dadosPanorama['linhas'][$maquina->id] ?? [],
                'marcas' => app(Timeline::class)->marcas($periodo->inicio, $periodo->fim),
                'agora' => ($agora = now()->getTimestamp()) > $periodo->inicio->getTimestamp() && $agora < $periodo->fim->getTimestamp()
                    ? round(($agora - $periodo->inicio->getTimestamp()) / ($periodo->fim->getTimestamp() - $periodo->inicio->getTimestamp()) * 100, 3) : null,
            ],
            // Produção por hora desta máquina (o mesmo cartão de Dashboards › Produção por hora): o turno de hoje ou,
            // num período que já terminou, o do último dia dele.
            'diaProducao' => $dia = ($periodo->fim->subSecond()->lessThan(CarbonImmutable::now()) ? $periodo->fim->subSecond() : CarbonImmutable::now())
                ->setTimezone(HorarioApontamento::fuso())->startOfDay(),
            'producaoHora' => $producaoHora->montar(new Collection([$maquina->loadMissing('ordemProducao')]), $dia)['maquinas'][0] ?? null,
            // Apontamentos reais (Timeline) que tocam o período, do mais recente para o mais antigo.
            // (sem as legendas com "Exibir em dashboards" = Não; a Timeline mostra todas)
            'apontamentosReais' => ApontamentoEstado::with(['legenda', 'ordemProducao'])->where('maquina_id', $maquina->id)
                ->whereNotIn('legenda_id', Legenda::withoutGlobalScopes()->where('exibir_dashboards', false)->select('id'))
                ->tocando($periodo->inicio, $periodo->fim)
                ->orderByDesc('inicio')->limit(50)->get(),
            'ind' => $indicadores->daMaquina($maquina->id, $periodo->inicio, $periodo->fim),
            'apontamentos' => Apontamento::where('maquina_id', $maquina->id)
                ->where('inicio', '>=', $periodo->inicio)->where('inicio', '<', $periodo->fim)
                ->orderByDesc('inicio')->limit(15)->get(),
            // Ciclo a ciclo do dia escolhido (dentro do período), lido da IOT001 (CiclosIot).
            'diasCiclos' => $diasCiclos,
            'ciclos' => $ciclosIot->doDia($maquina, $diaCiclos),
            'paradaAberta' => Parada::with('motivo')->where('maquina_id', $maquina->id)->whereNull('fim')->latest('inicio')->first(),
        ]);
    }

    /**
     * Dias (locais) do período para o gráfico de ciclos, do mais recente ao mais antigo (sem o futuro), e o escolhido
     * (`?ciclos_dia=AAAA-MM-DD`; fora do período ou inválido = o mais recente).
     *
     * @return array{0: list<CarbonImmutable>, 1: CarbonImmutable}
     */
    private function diasDosCiclos(Periodo $periodo, mixed $escolhido): array
    {
        $fuso = HorarioApontamento::fuso();
        $hoje = CarbonImmutable::now($fuso)->startOfDay();
        $dias = [];
        for ($d = $periodo->fim->subSecond()->setTimezone($fuso)->startOfDay(); $d->greaterThanOrEqualTo($periodo->inicio->setTimezone($fuso)->startOfDay()); $d = $d->subDay()) {
            if ($d->lessThanOrEqualTo($hoje)) {
                $dias[] = $d;
            }
        }
        $dias = $dias ?: [$hoje];
        foreach ($dias as $d) {
            if ($d->format('Y-m-d') === (string) $escolhido) {
                return [$dias, $d];
            }
        }

        return [$dias, $dias[0]];
    }
}
