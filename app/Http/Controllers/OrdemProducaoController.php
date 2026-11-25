<?php

namespace App\Http\Controllers;

use App\Models\Legenda;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use App\Models\ProdutoProtheus;
use App\Models\Sincronizacao;
use App\Services\CustoOrdemProducao;
use App\Services\EngenhariaOrdemProducao;
use App\Services\PecasProduzidas;
use App\Services\PerdasOrdemProducao;
use App\Services\TiposMovimento;
use App\Services\TurnoDaMaquina;
use Carbon\CarbonImmutable;
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
            ->busca($busca) // número da OP, SKU ou descrição do produto
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
            // Custo simulado (baixas ÷ produzido) e divergência com o custo meta, só das OPs desta página.
            'custos' => $custo->resumos($ordens->getCollection()),
            'ultimaSincronizacao' => Sincronizacao::where('chave', 'ordens_producao')->where('situacao', 'sucesso')
                ->latest('iniciado_em')->value('iniciado_em'),
        ]);
    }

    /**
     * Detalhes da OP: kardex com os movimentos (SD3 e SD2), resumos e comparação com o custo meta (SB9); empenhos (SD4),
     * estrutura atual (SG1), roteiro (SG2) e ferramenta/molde (SH4) da OP.
     */
    public function show(OrdemProducao $ordem, CustoOrdemProducao $custo, TiposMovimento $tipos, EngenhariaOrdemProducao $engenharia, PecasProduzidas $pecas, PerdasOrdemProducao $perdasOp): View
    {
        abort_if($ordem->d_e_l_e_t_ === '*', 404);
        // Contagem IoT: as mesmas peças da Produção por hora; acerta a quantidade real gravada na OP se estiver diferente.
        $contagemIot = $pecas->contagemDaOp($ordem, config('oee.fuso_exibicao'));

        // Kardex e custo; depois empenhos (SD4), estrutura (SG1), roteiro (SG2) e ferramentas/moldes (SH4) da OP.
        // Apontamentos reais (Timeline) com esta OP, do mais recente para o mais antigo.
        // Lista: os 500 mais recentes (OP longa com IoT tem milhares); totais de tempo sobre TODOS, numa leitura leve.
        $apontamentosReais = $ordem->apontamentos()->with(['maquina', 'legenda'])->orderByDesc('inicio')->orderByDesc('id')->limit(500)->get();
        $produzindo = Legenda::withoutGlobalScopes()->where('tipo', 'produzindo')->pluck('id')->map(fn ($id) => (int) $id)->flip();
        $tempos = ['total' => 0.0, 'produzindo' => 0.0, 'quantidade' => 0];
        foreach ($ordem->apontamentos()->toBase()->get(['inicio', 'fim', 'legenda_id']) as $a) {
            $min = max(0, (CarbonImmutable::parse($a->fim ?? now())->getTimestamp() - CarbonImmutable::parse($a->inicio)->getTimestamp()) / 60);
            $tempos['total'] += $min;
            $tempos['produzindo'] += isset($produzindo[(int) $a->legenda_id]) ? $min : 0;
            $tempos['quantidade']++;
        }

        // Apontamento de perdas: lista (com as excluídas), PU do produto e máquinas para escolher (a da OP atual primeiro).
        $perdas = $perdasOp->daOp($ordem);
        $maquinasPerda = Maquina::where('ativo', true)->orderByRaw('CASE WHEN ordem_producao_id = ? THEN 0 ELSE 1 END', [(int) $ordem->id])
            ->orderBy('nome')->get(['id', 'codigo', 'nome', 'ordem_producao_id']);

        return view('ordens-producao.show', ['op' => $ordem, 'tipos' => $tipos, 'apontamentos' => $tempos['quantidade'],
            'perdas' => $perdas, 'resumoPerdas' => $perdasOp->resumo($ordem, $perdas), 'maquinasPerda' => $maquinasPerda,
            // turno sugerido: o de agora na máquina que está com esta OP (senão o 1)
            'turnoSugerido' => ($m = $maquinasPerda->first(fn ($m) => (int) $m->ordem_producao_id === (int) $ordem->id))
                ? (app(TurnoDaMaquina::class)->turnoEm(Maquina::withoutGlobalScopes()->find($m->id), CarbonImmutable::now()) ?? 1) : 1,
            'apontamentosReais' => $apontamentosReais, 'temposApontamentos' => $tempos, 'contagemIot' => $contagemIot]
            + $custo->kardex($ordem) + $engenharia->daOp($ordem));
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
            ->busca($termo) // número da OP, SKU ou descrição do produto
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
