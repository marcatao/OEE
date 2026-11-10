<?php

namespace App\Http\Controllers;

use App\Models\OrdemProducao;
use App\Models\ProdutoProtheus;
use App\Models\Sincronizacao;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Produção > Ordens de produção: consulta das OPs do Protheus (SC2), com o status calculado pelo portal.
 * Só leitura: os dados vêm da sincronização (Administração > Banco de dados).
 */
class OrdemProducaoController extends Controller
{
    public function index(Request $request): View
    {
        $busca = trim((string) $request->query('busca'));
        $status = array_values(array_intersect((array) $request->query('status', []), array_keys(OrdemProducao::STATUS)));

        $ordens = OrdemProducao::withCount('apontamentos')
            ->where('d_e_l_e_t_', '<>', '*')
            ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w->where('numero', 'like', "%{$busca}%")
                ->orWhere('c2_produto', 'like', "%{$busca}%")
                ->orWhereIn('c2_produto', ProdutoProtheus::where('b1_desc', 'like', "%{$busca}%")->select('b1_cod'))))
            ->when($status, fn ($q) => $q->whereIn('status', $status))
            ->orderByRaw("CASE status WHEN 'parada' THEN 0 WHEN 'andamento' THEN 1 WHEN 'aberta' THEN 2 ELSE 3 END")
            ->orderByDesc('c2_emissao')->orderByDesc('numero')
            ->paginate(50)->withQueryString();

        return view('ordens-producao.index', [
            'ordens' => $ordens,
            'busca' => $busca,
            'status' => $status,
            'totais' => OrdemProducao::where('d_e_l_e_t_', '<>', '*')->select('status', DB::raw('count(*) as qtd'))
                ->groupBy('status')->pluck('qtd', 'status'),
            // Descrição só dos produtos desta página.
            'produtos' => ProdutoProtheus::whereIn('b1_cod', $ordens->pluck('c2_produto')->unique()->values())
                ->get()->keyBy(fn ($p) => $p->codigo()),
            'ultimaSincronizacao' => Sincronizacao::where('chave', 'ordens_producao')->where('situacao', 'sucesso')
                ->latest('iniciado_em')->value('iniciado_em'),
        ]);
    }
}
