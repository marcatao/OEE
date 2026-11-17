<?php

namespace App\Http\Controllers\Manutencao;

use App\Http\Controllers\Controller;
use App\Models\Compras\PedidoCompra;
use App\Models\Manutencao\SolicitacaoMaterial;
use App\Models\Manutencao\SolicitacaoMaterialItem;
use App\Models\ProdutoProtheus;
use App\Models\Usuario;
use App\Services\Compras\PedidoCompraMuro;
use App\Support\AcessoOs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Ordens de serviço › Solicitação de material: o mantenedor pede material (N itens) à administração da área; o
 * administrador da área (Manutenção ou Tecnologia) analisa ITEM a ITEM — aprova uma quantidade (já vem a solicitada,
 * pode mudar) ou reprova com motivo. Ficam gravados quem e quando pediu e quem e quando analisou. Gerar a requisição ao
 * almoxarifado a partir do item aprovado é a próxima etapa ("em breve"); o PEDIDO DE COMPRA dos itens aprovados vai para
 * o Protheus pela tabela muro (PedidoCompraController).
 */
class SolicitacaoMaterialController extends Controller
{
    public const MAX_ITENS = 50;

    public function index(Request $request): View
    {
        $u = $request->user();
        $status = (string) $request->input('status', AcessoOs::unidadesAdmin($u) ? 'pendentes' : '');
        $consulta = $this->visiveis($u)->with(['solicitante'])->withCount([
            'itens', 'itens as itens_pendentes_count' => fn ($q) => $q->where('status', 'pendente'),
            'itens as itens_aprovados_count' => fn ($q) => $q->where('status', 'aprovado'),
        ]);
        if ($status === 'pendentes') {
            $consulta->whereIn('status', ['pendente', 'em_analise']);
        } elseif (isset(SolicitacaoMaterial::STATUS[$status])) {
            $consulta->where('status', $status);
        }
        if ($request->boolean('minhas')) {
            $consulta->where('solicitante_id', $u->id);
        }

        return view('manutencao.materiais-solicitacao.index', [
            'solicitacoes' => $consulta->orderByDesc('id')->paginate(30)->withQueryString(),
            'status' => $status,
            'totais' => $this->visiveis($u)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'podeSolicitar' => AcessoOs::unidadesMaterial($u) !== [],
        ]);
    }

    public function create(Request $request): View
    {
        $u = $request->user();
        $unidades = AcessoOs::unidadesMaterial($u);
        abort_if($unidades === [], 403, 'Você não tem área de manutenção para pedir material.');
        $codigos = collect(old('itens', []))->pluck('produto_codigo')->filter()->unique();

        return view('manutencao.materiais-solicitacao.form', [
            'unidades' => $unidades,
            'centros' => AcessoOs::centrosCusto(),
            'ccUsuario' => AcessoOs::centroCustoDo($u),
            'produtosAntigos' => $codigos->isEmpty() ? collect() : $this->consultaProdutos()->whereIn('b1_cod', $codigos)->get()->keyBy(fn ($p) => trim($p->b1_cod)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $u = $request->user();
        $unidades = AcessoOs::unidadesMaterial($u);
        abort_if($unidades === [], 403);
        $request->mergeIfMissing(['unidade' => $unidades[0]]);
        // Linhas totalmente vazias (sem produto) são ignoradas.
        $request->merge(['itens' => array_values(array_filter((array) $request->input('itens', []), fn ($i) => trim((string) ($i['produto_codigo'] ?? '')) !== ''))]);
        $dados = $request->validate([
            'unidade' => ['required', Rule::in($unidades)],
            'observacao' => ['nullable', 'string', 'max:1000'],
            'itens' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITENS],
            'itens.*.produto_codigo' => ['required', 'string', 'max:30'],
            'itens.*.centro_custo' => ['required', 'string', 'max:20'],
            'itens.*.quantidade' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'itens.*.prioridade' => ['required', Rule::in(array_keys(SolicitacaoMaterialItem::prioridades()))],
            'itens.*.observacao' => ['nullable', 'string', 'max:1000'],
        ], [
            'itens.required' => 'Inclua pelo menos um item (escolha o produto).',
            'itens.*.centro_custo.required' => 'Escolha o centro de custo de cada item.',
            'itens.*.quantidade.required' => 'Informe a quantidade de cada item.',
            'itens.*.quantidade.gt' => 'A quantidade precisa ser maior que zero.',
            'itens.*.prioridade.in' => 'Escolha uma prioridade da lista.',
        ]);

        $produtos = $this->consultaProdutos()->whereIn('b1_cod', collect($dados['itens'])->pluck('produto_codigo')->map(fn ($c) => trim($c))->unique())->get()
            ->keyBy(fn ($p) => trim($p->b1_cod));
        $erros = [];
        foreach ($dados['itens'] as $i => $item) {
            if (! isset($produtos[trim($item['produto_codigo'])])) {
                $erros["itens.{$i}.produto_codigo"] = 'Item '.($i + 1).': produto não encontrado (ou bloqueado) no cadastro.';
            }
            if (! AcessoOs::centroCustoExiste($item['centro_custo'])) {
                $erros["itens.{$i}.centro_custo"] = 'Item '.($i + 1).': escolha um centro de custo da lista.';
            }
        }
        if ($erros) {
            throw ValidationException::withMessages($erros);
        }

        $sm = DB::transaction(function () use ($u, $dados, $produtos) {
            $sm = SolicitacaoMaterial::create(['unidade' => $dados['unidade'], 'solicitante_id' => $u->id, 'status' => 'pendente',
                'observacao' => $dados['observacao'] ?? null]);
            foreach ($dados['itens'] as $item) {
                $p = $produtos[trim($item['produto_codigo'])];
                $sm->itens()->create([
                    'produto_codigo' => trim($p->b1_cod), 'produto_descricao' => mb_substr($p->descricao(), 0, 120), 'um' => trim((string) $p->b1_um),
                    'centro_custo' => trim($item['centro_custo']), 'quantidade_solicitada' => $item['quantidade'],
                    'prioridade' => $item['prioridade'], 'observacao' => $item['observacao'] ?? null, 'status' => 'pendente',
                ]);
            }

            return $sm;
        });

        return redirect()->route('os.materiais-solicitacao.show', $sm)
            ->with('ok', "Solicitação {$sm->numeroFormatado()} enviada para a administração de ".(config('oee.unidades')[$sm->unidade] ?? $sm->unidade).'.');
    }

    public function show(Request $request, int $solicitacao): View
    {
        $sm = $this->visiveis($request->user())->with(['solicitante', 'itens.aprovadoPor'])->findOrFail($solicitacao);
        $centros = AcessoOs::centrosCusto()->keyBy(fn ($c) => $c->codigo());
        $admin = AcessoOs::ehAdminDa($request->user(), $sm->unidade);
        // Pedidos de compra desta solicitação, com a situação lida agora na tabela muro do Protheus.
        $pedidos = PedidoCompra::with(['itens', 'criadoPor'])->where('solicitacao_material_id', $sm->id)->orderByDesc('id')->get();
        $muroOk = app(PedidoCompraMuro::class)->atualizarSemFalhar($pedidos);
        $emPedido = PedidoCompraController::itensEmPedido($sm->itens->pluck('id')->all());
        $paraPedido = $sm->itens->where('status', 'aprovado')->filter(fn ($i) => (float) $i->quantidade_aprovada > 0 && ! isset($emPedido[$i->id]));

        return view('manutencao.materiais-solicitacao.show', [
            'sm' => $sm,
            'centros' => $centros,
            'pedidos' => $pedidos,
            'muroOk' => $muroOk,
            'emPedido' => $emPedido,
            'podeGerarPedido' => $admin && $sm->status !== 'cancelada' && $paraPedido->isNotEmpty(),
            'ccPedido' => (string) ($paraPedido->first()?->centro_custo ?? ''),
            'podeAnalisar' => $admin && $sm->status !== 'cancelada',
            'podeCancelar' => $sm->status === 'pendente' && ((int) $sm->solicitante_id === (int) $request->user()->id || AcessoOs::ehAdminDa($request->user(), $sm->unidade)),
        ]);
    }

    /**
     * Análise item a item pelo administrador da área: "aprovar" (quantidade aprovada > 0), "reprovar" (com motivo) ou
     * "aprovar_todos" (todos os pendentes com as quantidades da tela).
     */
    public function analisar(Request $request, int $solicitacao): RedirectResponse
    {
        $u = $request->user();
        $sm = $this->visiveis($u)->with('itens')->findOrFail($solicitacao);
        abort_unless(AcessoOs::ehAdminDa($u, $sm->unidade), 403, 'Só o administrador da área analisa a solicitação de material.');
        abort_if($sm->status === 'cancelada', 422, 'Solicitação cancelada.');
        $dados = $request->validate([
            'acao' => ['required', Rule::in(['aprovar', 'reprovar', 'aprovar_todos'])],
            'item' => ['required_unless:acao,aprovar_todos', 'nullable', 'integer'],
            'quantidade' => ['array'], 'quantidade.*' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'motivo' => ['array'], 'motivo.*' => ['nullable', 'string', 'max:500'],
        ]);
        $pendentes = $sm->itens->where('status', 'pendente');
        $alvos = $dados['acao'] === 'aprovar_todos' ? $pendentes : $pendentes->where('id', (int) $dados['item']);
        if ($alvos->isEmpty()) {
            throw ValidationException::withMessages(['item' => 'Este item já foi analisado.']);
        }

        DB::transaction(function () use ($alvos, $dados, $u) {
            foreach ($alvos as $item) {
                if ($dados['acao'] === 'reprovar') {
                    $motivo = trim((string) ($dados['motivo'][$item->id] ?? ''));
                    if ($motivo === '') {
                        throw ValidationException::withMessages(["motivo.{$item->id}" => 'Informe o motivo da reprovação do item '.$item->produto_codigo.'.']);
                    }
                    $item->forceFill(['status' => 'reprovado', 'quantidade_aprovada' => 0, 'motivo_reprovacao' => $motivo,
                        'aprovado_por_id' => $u->id, 'aprovado_em' => now()])->save();

                    continue;
                }
                $qtd = $dados['quantidade'][$item->id] ?? $item->quantidade_solicitada;
                if ($qtd === null || $qtd === '' || (float) $qtd <= 0) {
                    throw ValidationException::withMessages(["quantidade.{$item->id}" => 'Item '.$item->produto_codigo.': a quantidade aprovada precisa ser maior que zero (para não liberar nada, reprove).']);
                }
                $item->forceFill(['status' => 'aprovado', 'quantidade_aprovada' => $qtd, 'motivo_reprovacao' => null,
                    'aprovado_por_id' => $u->id, 'aprovado_em' => now()])->save();
            }
        });
        $sm->recalcularStatus();

        return redirect()->route('os.materiais-solicitacao.show', $sm)->with('ok', match ($dados['acao']) {
            'aprovar_todos' => $alvos->count().' item(ns) aprovado(s).',
            'aprovar' => 'Item aprovado.',
            default => 'Item reprovado.',
        });
    }

    public function cancelar(Request $request, int $solicitacao): RedirectResponse
    {
        $u = $request->user();
        $sm = $this->visiveis($u)->findOrFail($solicitacao);
        abort_unless($sm->status === 'pendente' && ((int) $sm->solicitante_id === (int) $u->id || AcessoOs::ehAdminDa($u, $sm->unidade)), 403,
            'Só dá para cancelar enquanto nenhum item foi analisado (o solicitante ou o administrador da área).');
        $sm->forceFill(['status' => 'cancelada'])->save();

        return redirect()->route('os.materiais-solicitacao.show', $sm)->with('ok', 'Solicitação cancelada.');
    }

    /** Busca de produtos (SB1) para a lista com busca: por código (começo) ou descrição (todas as palavras). */
    public function buscarProdutos(Request $request): JsonResponse
    {
        $termo = trim((string) $request->input('q'));
        if (mb_strlen($termo) < 2) {
            return response()->json([]);
        }
        $consulta = $this->consultaProdutos()->where(function ($q) use ($termo) {
            $q->where('b1_cod', 'like', $termo.'%')
                ->orWhere(function ($d) use ($termo) {
                    foreach (preg_split('/\s+/', $termo) as $palavra) {
                        $d->where('b1_desc', 'like', '%'.$palavra.'%');
                    }
                });
        });

        return response()->json($consulta->orderBy('b1_cod')->limit(60)->get(['b1_cod', 'b1_desc', 'b1_um'])
            ->unique(fn ($p) => trim($p->b1_cod))->take(30)
            ->map(fn ($p) => ['valor' => trim($p->b1_cod), 'texto' => trim($p->b1_cod).' — '.trim($p->b1_desc).(trim((string) $p->b1_um) !== '' ? ' ('.trim($p->b1_um).')' : '')])
            ->values());
    }

    /** Produtos que podem ser pedidos: não excluídos nem bloqueados no Protheus. */
    private function consultaProdutos(): Builder
    {
        return ProdutoProtheus::query()->where('d_e_l_e_t_', '<>', '*')->where('b1_msblql', '<>', '1');
    }

    /** O solicitante vê as dele; o administrador da área vê todas da área (Adm. Tecnologia: todas). */
    private function visiveis(Usuario $u): Builder
    {
        $admin = AcessoOs::unidadesAdmin($u);

        return SolicitacaoMaterial::query()->where(fn ($q) => $q->where('solicitante_id', $u->id)->when($admin, fn ($q) => $q->orWhereIn('unidade', $admin)));
    }
}
