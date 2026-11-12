<?php

namespace App\Http\Controllers;

use App\Models\EstruturaProduto;
use App\Models\ProdutoProtheus;
use App\Models\Sincronizacao;
use App\Services\CustoOrdemProducao;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Produção > Estruturas de produto: consulta da SG1 do Protheus (componentes de cada produto). Só leitura.
 * Clicar num componente que também tem estrutura abre a estrutura dele (níveis seguintes).
 */
class EstruturaProdutoController extends Controller
{
    public function index(Request $request, CustoOrdemProducao $custo): View
    {
        $busca = trim((string) $request->query('busca'));
        $produto = trim((string) $request->query('produto'));
        $validade = $request->query('validade') === 'todas' ? 'todas' : 'vigentes';
        $hoje = now(config('oee.fuso_exibicao'))->format('Ymd');

        $linhas = EstruturaProduto::validas()
            ->when($validade === 'vigentes', fn ($q) => $q->vigentes($hoje))
            ->when($produto !== '', fn ($q) => $q->where('g1_cod', $produto))
            ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w->where('g1_cod', 'like', "%{$busca}%")
                ->orWhere('g1_comp', 'like', "%{$busca}%")
                ->orWhereIn('g1_cod', ProdutoProtheus::where('b1_desc', 'like', "%{$busca}%")->select('b1_cod'))))
            ->orderBy('g1_cod')->orderBy('g1_trt')->orderBy('g1_comp')
            ->paginate(100)->withQueryString();

        $codigos = $linhas->pluck('g1_cod')->merge($linhas->pluck('g1_comp'))->map(fn ($c) => rtrim($c))->unique()->values();

        return view('producao.estruturas', [
            'linhas' => $linhas,
            'produtos' => $custo->produtos($codigos),
            // Componentes que também têm estrutura (para o link "ver estrutura").
            'comEstrutura' => array_flip(EstruturaProduto::validas()->whereIn('g1_cod', $codigos->all())->distinct()->pluck('g1_cod')
                ->map(fn ($c) => rtrim($c))->all()),
            'busca' => $busca,
            'produto' => $produto,
            'validade' => $validade,
            'ultimaSincronizacao' => Sincronizacao::where('chave', 'estruturas')->where('situacao', 'sucesso')->latest('iniciado_em')->value('iniciado_em'),
        ]);
    }
}
