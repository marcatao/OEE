<?php

namespace App\Http\Controllers;

use App\Models\EmpenhoOp;
use App\Models\OrdemProducao;
use App\Models\ProdutoProtheus;
use App\Models\Sincronizacao;
use App\Services\CustoOrdemProducao;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Produção > Empenhos OP: empenhos do Protheus (SD4, cópia em empenhos_op), só para consulta. Cada linha é um
 * componente reservado para uma OP: empenhado (D4_QTDEORI), baixado e saldo (D4_QUANT).
 */
class EmpenhoOpController extends Controller
{
    public function index(Request $request, CustoOrdemProducao $custo): View
    {
        $busca = trim((string) $request->query('busca'));
        $saldo = $request->query('saldo', '') === '1';
        $abertas = $request->query('abertas', '1') === '1';

        $empenhos = EmpenhoOp::validos()
            ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w->where('op_numero', 'like', "%{$busca}%")
                ->orWhere('d4_cod', 'like', "%{$busca}%")
                ->orWhere('d4_lotectl', 'like', "%{$busca}%")
                ->orWhereIn('d4_cod', ProdutoProtheus::where('b1_desc', 'like', "%{$busca}%")->select('b1_cod'))))
            ->when($saldo, fn ($q) => $q->where('d4_quant', '>', 0))
            ->when($abertas, fn ($q) => $q->whereIn('op_numero', OrdemProducao::where('status', '<>', 'fechada')
                ->where('d_e_l_e_t_', '<>', '*')->select('numero')))
            ->orderByDesc('d4_data')->orderBy('op_numero')->orderBy('d4_cod')
            ->paginate(50)->withQueryString();

        $ops = OrdemProducao::whereIn('numero', $empenhos->pluck('op_numero')->unique()->values()->all())
            ->where('d_e_l_e_t_', '<>', '*')->get()->keyBy(fn ($op) => rtrim($op->c2_filial).'|'.$op->numero);

        return view('producao.empenhos', [
            'empenhos' => $empenhos,
            'ops' => $ops,
            'produtos' => $custo->produtos($empenhos->pluck('d4_cod')->merge($ops->pluck('c2_produto'))),
            'busca' => $busca,
            'saldo' => $saldo,
            'abertas' => $abertas,
            'ultimaSincronizacao' => Sincronizacao::where('chave', 'empenhos_op')->where('situacao', 'sucesso')
                ->latest('iniciado_em')->value('iniciado_em'),
        ]);
    }
}
