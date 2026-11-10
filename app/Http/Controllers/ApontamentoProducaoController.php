<?php

namespace App\Http\Controllers;

use App\Models\OrdemProducao;
use App\Models\ProdutoProtheus;
use App\Models\Sincronizacao;
use App\Services\CustoOrdemProducao;
use App\Services\TiposMovimento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Produção > Apontamentos OP: movimentos do Protheus com OP, só para consulta.
 * SD3 (produções e baixas/consumos, não excluídos nem estornados) e SD2 (notas fiscais com OP), valorizados pelo
 * D3_CUSTO1 / D2_CUSTO1. Os dados vêm da sincronização (Administração > Banco de dados).
 */
class ApontamentoProducaoController extends Controller
{
    public function index(Request $request, CustoOrdemProducao $custo, TiposMovimento $tipos): View
    {
        $busca = trim((string) $request->query('busca'));
        $tipo = array_values(array_intersect((array) $request->query('tipo', []), array_keys($tipos->tipos())));
        $data = fn (string $campo) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($campo))
            ? (string) $request->query($campo) : '';
        $de = $data('de');
        $ate = $data('ate');

        $filtrada = $custo->movimentos()
            ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w->where('op_numero', 'like', "%{$busca}%")
                ->orWhere('produto', 'like', "%{$busca}%")
                ->orWhere('documento', 'like', "%{$busca}%")
                ->orWhereIn('produto', ProdutoProtheus::where('b1_desc', 'like', "%{$busca}%")->select('b1_cod'))))
            ->when($tipo, fn ($q) => $q->whereIn('tipo', $tipo))
            ->when($de !== '', fn ($q) => $q->where('emissao', '>=', str_replace('-', '', $de)))
            ->when($ate !== '', fn ($q) => $q->where('emissao', '<=', str_replace('-', '', $ate)));

        $totais = (clone $filtrada)->groupBy('tipo')
            ->select('tipo', DB::raw('count(*) as linhas'), DB::raw('sum(quantidade) as qtd'), DB::raw('sum(custo) as valor'))
            ->get()->keyBy('tipo');

        $movimentos = $filtrada->orderByDesc('emissao')->orderByDesc('r_e_c_n_o_')->orderBy('origem')
            ->paginate(50)->withQueryString();

        // OPs desta página (para o link do kardex) e descrição dos produtos.
        $ops = OrdemProducao::whereIn('numero', $movimentos->pluck('op_numero')->unique()->values()->all())
            ->get(['id', 'numero', 'c2_filial', 'c2_produto'])->keyBy(fn ($op) => rtrim($op->c2_filial).'|'.$op->numero);

        return view('apontamentos-producao.index', [
            'movimentos' => $movimentos,
            'totais' => $totais,
            'ops' => $ops,
            'produtos' => $custo->produtos($movimentos->pluck('produto')),
            'tipos' => $tipos,
            'busca' => $busca,
            'tipo' => $tipo,
            'de' => $de,
            'ate' => $ate,
            'ultimaSincronizacao' => Sincronizacao::where('chave', 'movimentos_producao')->where('situacao', 'sucesso')
                ->latest('iniciado_em')->value('iniciado_em'),
        ]);
    }
}
