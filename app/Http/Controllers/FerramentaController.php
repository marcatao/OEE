<?php

namespace App\Http\Controllers;

use App\Models\Ferramenta;
use App\Models\RoteiroOperacao;
use App\Models\Sincronizacao;
use App\Support\Unidades;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Produção > Ferramentas: SH4 do Protheus (tabela mista). Consulta para quem tem a permissão "ferramentas"; os
 * administradores editam só o que é do portal: cavidades ativas (até a quantidade de cavidades do Protheus) e unidade.
 */
class FerramentaController extends Controller
{
    public function index(Request $request): View
    {
        $busca = trim((string) $request->query('busca'));

        $ferramentas = Ferramenta::validas()
            ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w->where('h4_codigo', 'like', "%{$busca}%")
                ->orWhere('h4_descri', 'like', "%{$busca}%")))
            ->orderBy('h4_codigo')
            ->paginate(50)->withQueryString();

        return view('producao.ferramentas.index', [
            'ferramentas' => $ferramentas,
            'usos' => RoteiroOperacao::validas()
                ->whereIn('g2_ferram', $ferramentas->getCollection()->map(fn ($f) => $f->codigo())->all())
                ->groupBy('g2_ferram')->select('g2_ferram', DB::raw('count(*) as qtd'))->pluck('qtd', 'g2_ferram')
                ->mapWithKeys(fn ($qtd, $cod) => [rtrim($cod) => $qtd]),
            'busca' => $busca,
            'ultimaSincronizacao' => Sincronizacao::where('chave', 'ferramentas')->where('situacao', 'sucesso')->latest('iniciado_em')->value('iniciado_em'),
        ]);
    }

    public function edit(Ferramenta $ferramenta): View
    {
        abort_if($ferramenta->d_e_l_e_t_ === '*', 404);

        return view('producao.ferramentas.form', [
            'ferramenta' => $ferramenta,
            'roteiros' => RoteiroOperacao::validas()->where('g2_ferram', $ferramenta->codigo())
                ->orderBy('g2_produto')->orderBy('g2_operac')->limit(200)->get(),
        ]);
    }

    public function update(Request $request, Ferramenta $ferramenta): RedirectResponse
    {
        abort_if($ferramenta->d_e_l_e_t_ === '*', 404);
        $dados = $request->validate([
            'unidade' => Unidades::regras($request->user(), $ferramenta->unidade),
            'h4_qtdcavativas' => ['required', 'integer', 'min:0', 'max:'.max(0, (int) $ferramenta->h4_qtdcav)],
        ], [
            'h4_qtdcavativas.max' => 'As cavidades ativas não podem passar da quantidade de cavidades da ferramenta ('.$ferramenta->h4_qtdcav.').',
            'h4_qtdcavativas.*' => 'Informe as cavidades ativas (número inteiro, a partir de 0).',
        ]);
        $ferramenta->fill($dados)->save();

        return redirect()->route('ferramentas.index')->with('ok', "Ferramenta {$ferramenta->codigo()} atualizada.");
    }
}
