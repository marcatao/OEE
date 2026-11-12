<?php

namespace App\Http\Controllers;

use App\Models\OrdemProducao;
use App\Models\ProdutoProtheus;
use App\Models\Sincronizacao;
use App\Services\CustoOrdemProducao;
use App\Services\TiposMovimento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Produção > Ordens de produção: consulta das OPs do Protheus (SC2), com o status calculado pelo portal.
 * Só leitura: os dados vêm da sincronização (Administração > Banco de dados).
 */
class OrdemProducaoController extends Controller
{
    public function index(Request $request, CustoOrdemProducao $custo): View
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
            // Custo atual (baixas − devoluções ÷ produzido) e divergência com o custo meta, só das OPs desta página.
            'custos' => $custo->resumos($ordens->getCollection()),
            'ultimaSincronizacao' => Sincronizacao::where('chave', 'ordens_producao')->where('situacao', 'sucesso')
                ->latest('iniciado_em')->value('iniciado_em'),
        ]);
    }

    /** Detalhes da OP: kardex com os movimentos (SD3 e SD2), resumos e comparação com o custo meta (SB9). */
    public function show(OrdemProducao $ordem, CustoOrdemProducao $custo, TiposMovimento $tipos): View
    {
        abort_if($ordem->d_e_l_e_t_ === '*', 404);

        return view('ordens-producao.show', ['op' => $ordem, 'tipos' => $tipos, 'apontamentos' => $ordem->apontamentos()->count()]
            + $custo->kardex($ordem));
    }

    /**
     * Busca de OP para os campos de seleção (lista com busca no servidor): OPs que ainda podem ser apontadas, pelo
     * número, pelo código ou pela descrição do produto. Até 30. Resposta: [{valor: número, texto}].
     */
    public function busca(Request $request, CustoOrdemProducao $custo): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->pode('apontamentos') || $usuario->pode('ordens_producao') || $usuario->ehAdmin(), 403);
        $termo = trim((string) $request->query('q'));
        if (mb_strlen($termo) < 2) {
            return response()->json([]);
        }
        $ops = OrdemProducao::apontaveis()
            ->where(fn ($q) => $q->where('numero', 'like', "%{$termo}%")
                ->orWhere('c2_produto', 'like', "%{$termo}%")
                ->orWhereIn('c2_produto', ProdutoProtheus::where('b1_desc', 'like', "%{$termo}%")->select('b1_cod')))
            ->orderByRaw("CASE status WHEN 'andamento' THEN 0 WHEN 'parada' THEN 1 ELSE 2 END")
            ->orderByDesc('c2_emissao')->orderByDesc('numero')
            ->limit(30)->get();
        $produtos = $custo->produtos($ops->pluck('c2_produto'));

        return response()->json($ops->map(fn ($op) => [
            'valor' => $op->numero,
            'texto' => $op->textoBusca($produtos[rtrim($op->c2_produto)] ?? null),
        ])->values());
    }
}
