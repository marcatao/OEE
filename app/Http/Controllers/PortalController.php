<?php

namespace App\Http\Controllers;

use App\Models\DispositivoIot;
use App\Models\Maquina;
use App\Models\Parada;
use App\Models\Usuario;
use App\Services\CalculadoraOee;
use App\Services\IndicadoresOee;
use App\Services\Periodo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class PortalController extends Controller
{
    public function inicio(Request $request, IndicadoresOee $indicadores): View
    {
        $usuario = $request->user();
        if (! $usuario?->temAcesso()) {
            return view('inicio');
        }

        $hoje = Periodo::de('hoje');

        return view('inicio', [
            'hoje' => $usuario->pode('dashboard_maquina') ? $indicadores->geral($hoje->inicio, $hoje->fim) : null,
            'maquinasAtivas' => Maquina::where('ativo', true)->count(),
            'paradasAbertas' => Parada::whereNull('fim')->count(),
            'iotAtivos' => DispositivoIot::where('ativo', true)->count(),
            'iotOnline' => DispositivoIot::where('ativo', true)
                ->where('ultimo_sinal_em', '>=', now()->subMinutes(config('oee.iot_minutos_sem_sinal', 5)))->count(),
            'aguardando' => $usuario->ehAdmin() ? Usuario::where('perfil', 'sem_acesso')->where('ativo', true)->count() : 0,
        ]);
    }

    public function calculadora(Request $request, CalculadoraOee $calculadora): View
    {
        $resultado = null;
        $erro = null;

        if ($request->isMethod('post')) {
            $dados = $request->validate([
                'tempo_planejado' => 'required|numeric|min:0',
                'paradas' => 'required|numeric|min:0',
                'ciclo_ideal' => 'required|numeric|min:0',
                'total_produzido' => 'required|integer|min:0',
                'pecas_boas' => 'required|integer|min:0',
            ], array_fill_keys(['required', 'numeric', 'integer', 'min'], 'Preencha todos os campos com números válidos.'));

            try {
                $resultado = $calculadora->calcular(
                    (float) $dados['tempo_planejado'],
                    (float) $dados['paradas'],
                    (float) $dados['ciclo_ideal'],
                    (int) $dados['total_produzido'],
                    (int) $dados['pecas_boas'],
                );
            } catch (InvalidArgumentException $e) {
                $erro = $e->getMessage();
            }
        }

        return view('calculadora', ['resultado' => $resultado, 'erro' => $erro]);
    }

    /** Página de quem entrou pelo Entra ID mas ainda não foi liberado por um administrador. */
    public function aguardando(Request $request): View|RedirectResponse
    {
        if ($request->user()->temAcesso()) {
            return redirect()->route('inicio');
        }

        return view('aguardando');
    }
}
