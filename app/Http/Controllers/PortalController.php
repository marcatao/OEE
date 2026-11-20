<?php

namespace App\Http\Controllers;

use App\Models\DispositivoIot;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use App\Models\Parada;
use App\Models\ProdutoProtheus;
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
    private const OPS_NA_INICIAL = 30;

    public function inicio(Request $request, IndicadoresOee $indicadores): View
    {
        $usuario = $request->user();
        if (! $usuario?->temAcesso()) {
            return view('inicio');
        }

        $hoje = Periodo::de('hoje');

        // OPs abertas (não fechadas e não excluídas no Protheus): só para quem vê a tela de ordens de produção.
        // Contagem por status no banco; a lista mostra só as primeiras (paradas, depois em andamento) — o resto na tela de OPs.
        $opsAbertas = null;
        $contagemOps = collect();
        $produtosOps = collect();
        if ($usuario->pode('ordens_producao')) {
            $contagemOps = OrdemProducao::apontaveis()->selectRaw('status, count(*) as total')->groupBy('status')
                ->pluck('total', 'status')->map(fn ($t) => (int) $t);
            $opsAbertas = OrdemProducao::apontaveis()
                ->orderByRaw("CASE status WHEN 'parada' THEN 0 WHEN 'andamento' THEN 1 ELSE 2 END")
                ->orderBy('c2_datprf')->orderBy('numero')->limit(self::OPS_NA_INICIAL)
                ->get(['id', 'numero', 'c2_filial', 'c2_produto', 'c2_um', 'c2_quant', 'c2_quje', 'c2_datpri', 'c2_datprf', 'status']);
            $produtosOps = ProdutoProtheus::whereIn('b1_cod', $opsAbertas->pluck('c2_produto')->unique()->values()->all() ?: [''])
                ->get()->keyBy(fn ($p) => $p->codigo());
        }

        return view('inicio', [
            'opsAbertas' => $opsAbertas,
            'contagemOps' => $contagemOps,
            'produtosOps' => $produtosOps,
            'hoje' => $usuario->pode('dashboard_maquina') ? $indicadores->geral($hoje->inicio, $hoje->fim) : null,
            'maquinasAtivas' => Maquina::where('ativo', true)->count(),
            'paradasAbertas' => Parada::whereNull('fim')->count(),
            'iotAtivos' => DispositivoIot::where('ativo', true)->count(),
            'iotOnline' => DispositivoIot::where('ativo', true)
                ->where('ultimo_sinal_em', '>=', now()->subMinutes(config('oee.iot_minutos_sem_sinal', 5)))->count(),
            'aguardando' => $usuario->podeSeguranca() ? Usuario::where('perfil', 'sem_acesso')->where('ativo', true)->count() : 0,
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
