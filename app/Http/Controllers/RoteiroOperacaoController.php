<?php

namespace App\Http\Controllers;

use App\Models\Ferramenta;
use App\Models\Maquina;
use App\Models\ProdutoProtheus;
use App\Models\RoteiroOperacao;
use App\Models\Sincronizacao;
use App\Services\CustoOrdemProducao;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Produção > Roteiros de operação: consulta da SG2 do Protheus (operações, centro de trabalho = máquina, ferramenta e
 * tempos). Só leitura.
 */
class RoteiroOperacaoController extends Controller
{
    public function index(Request $request, CustoOrdemProducao $custo): View
    {
        $busca = trim((string) $request->query('busca'));
        $ferramenta = trim((string) $request->query('ferramenta'));

        $linhas = RoteiroOperacao::validas()
            ->when($ferramenta !== '', fn ($q) => $q->where('g2_ferram', $ferramenta))
            ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w->where('g2_produto', 'like', "%{$busca}%")
                ->orWhere('g2_descri', 'like', "%{$busca}%")
                ->orWhere('g2_ctrab', 'like', "%{$busca}%")
                ->orWhere('g2_recurso', 'like', "%{$busca}%")
                ->orWhere('g2_ferram', 'like', "%{$busca}%")
                ->orWhereIn('g2_produto', ProdutoProtheus::where('b1_desc', 'like', "%{$busca}%")->select('b1_cod'))))
            ->orderBy('g2_produto')->orderBy('g2_codigo')->orderBy('g2_operac')
            ->paginate(100)->withQueryString();

        return view('producao.roteiros', [
            'linhas' => $linhas,
            'produtos' => $custo->produtos($linhas->pluck('g2_produto')),
            // Máquinas (centros de trabalho) e ferramentas desta página que o usuário enxerga (unidade).
            'maquinas' => Maquina::whereIn('codigo', $linhas->pluck('g2_ctrab')->map(fn ($c) => rtrim($c))->filter()->unique()->values()->all())
                ->get()->keyBy('codigo'),
            'ferramentas' => Ferramenta::whereIn('h4_codigo', $linhas->pluck('g2_ferram')->map(fn ($c) => rtrim($c))->filter()->unique()->values()->all())
                ->get()->keyBy(fn ($f) => $f->codigo()),
            'busca' => $busca,
            'ferramenta' => $ferramenta,
            'ultimaSincronizacao' => Sincronizacao::where('chave', 'roteiros')->where('situacao', 'sucesso')->latest('iniciado_em')->value('iniciado_em'),
        ]);
    }
}
