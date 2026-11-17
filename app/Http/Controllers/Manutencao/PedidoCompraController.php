<?php

namespace App\Http\Controllers\Manutencao;

use App\Http\Controllers\Controller;
use App\Models\Compras\PedidoCompra;
use App\Models\Compras\PedidoCompraItem;
use App\Models\Manutencao\OrdemServico;
use App\Models\Manutencao\SolicitacaoMaterial;
use App\Models\ProdutoProtheus;
use App\Models\Usuario;
use App\Services\Compras\PedidoCompraMuro;
use App\Support\AcessoOs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Pedido de compra ou solicitação de compra pelo Protheus (tabela muro; campo "tipo"): o ADMINISTRADOR DA UNIDADE gera
 * escolhendo os itens aprovados
 * de uma solicitação de material, ou os produtos direto numa OS aberta, com centro de custo de origem e de destino.
 * Cancelar ou reenviar só enquanto o Protheus não pegou o pedido (ou deu erro).
 */
class PedidoCompraController extends Controller
{
    public const MAX_ITENS = 50;

    public function __construct(private readonly PedidoCompraMuro $muro) {}

    /** Itens aprovados da solicitação de material (os que ainda não estão em outro pedido ativo). */
    public function daSolicitacao(Request $request, int $solicitacao): RedirectResponse
    {
        $u = $request->user();
        $sm = SolicitacaoMaterial::with('itens')->findOrFail($solicitacao);
        abort_unless(AcessoOs::ehAdminDa($u, $sm->unidade), 403, 'Só o administrador da unidade gera pedido ou solicitação de compra.');
        $dados = $request->validate($this->regrasCabecalho() + [
            'pedido_itens' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITENS], 'pedido_itens.*' => ['integer'],
        ], $this->mensagens() + ['pedido_itens.required' => 'Marque os itens aprovados que vão para o Protheus.']);
        $this->conferirCentros($dados);

        $emPedido = self::itensEmPedido($sm->itens->pluck('id')->all());
        $itens = [];
        foreach (array_unique($dados['pedido_itens']) as $id) {
            $item = $sm->itens->firstWhere('id', (int) $id);
            if ($item === null || $item->status !== 'aprovado' || (float) $item->quantidade_aprovada <= 0) {
                throw ValidationException::withMessages(['pedido_itens' => 'Só itens APROVADOS vão para o Protheus.']);
            }
            if (isset($emPedido[$item->id])) {
                throw ValidationException::withMessages(['pedido_itens' => "O item {$item->produto_codigo} já está em {$emPedido[$item->id]}."]);
            }
            $itens[] = ['solicitacao_material_item_id' => $item->id, 'produto_codigo' => $item->produto_codigo, 'produto_descricao' => $item->produto_descricao,
                'um' => $item->um, 'quantidade' => $item->quantidade_aprovada, 'observacao' => $item->observacao];
        }

        return $this->gerar($request, $u, $dados + ['unidade' => $sm->unidade, 'solicitacao_material_id' => $sm->id,
            'origem' => $sm->numeroFormatado()], $itens, route('os.materiais-solicitacao.show', $sm).'#pedidos-compra');
    }

    /** Produtos escolhidos direto na OS (só com a OS aberta). */
    public function daOrdem(Request $request, int $ordem): RedirectResponse
    {
        $u = $request->user();
        $os = AcessoOs::filtrar(OrdemServico::query(), $u)->findOrFail($ordem);
        abort_unless(AcessoOs::ehAdminDa($u, $os->unidade), 403, 'Só o administrador da unidade gera pedido ou solicitação de compra.');
        abort_unless($os->estaAberta(), 422, 'A OS não está aberta: não dá mais para gerar pedido ou solicitação de compra.');
        $request->merge(['itens' => array_values(array_filter((array) $request->input('itens', []), fn ($i) => trim((string) ($i['produto_codigo'] ?? '')) !== ''))]);
        $dados = $request->validate($this->regrasCabecalho() + [
            'itens' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITENS],
            'itens.*.produto_codigo' => ['required', 'string', 'max:30'],
            'itens.*.quantidade' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'itens.*.observacao' => ['nullable', 'string', 'max:100'],
        ], $this->mensagens() + [
            'itens.required' => 'Inclua pelo menos um produto no pedido.',
            'itens.*.quantidade.required' => 'Informe a quantidade de cada produto.',
            'itens.*.quantidade.gt' => 'A quantidade precisa ser maior que zero.',
        ]);
        $this->conferirCentros($dados);

        $produtos = ProdutoProtheus::query()->where('d_e_l_e_t_', '<>', '*')->where('b1_msblql', '<>', '1')
            ->whereIn('b1_cod', collect($dados['itens'])->pluck('produto_codigo')->map(fn ($c) => trim($c))->unique())->get()
            ->keyBy(fn ($p) => trim($p->b1_cod));
        $itens = [];
        foreach ($dados['itens'] as $n => $linha) {
            $p = $produtos[trim($linha['produto_codigo'])] ?? null;
            if ($p === null) {
                throw ValidationException::withMessages(["itens.{$n}.produto_codigo" => 'Item '.($n + 1).': produto não encontrado (ou bloqueado) no cadastro.']);
            }
            $itens[] = ['produto_codigo' => trim($p->b1_cod), 'produto_descricao' => $p->descricao(), 'um' => trim((string) $p->b1_um),
                'quantidade' => $linha['quantidade'], 'observacao' => $linha['observacao'] ?? null];
        }

        return $this->gerar($request, $u, $dados + ['unidade' => $os->unidade, 'ordem_servico_id' => $os->id,
            'origem' => $os->numeroFormatado()], $itens, route('os.ordens.show', $os).'#pedidos-compra');
    }

    public function cancelar(Request $request, PedidoCompra $pedido): RedirectResponse
    {
        $this->exigirAdmin($request->user(), $pedido);
        $this->muro->cancelar($pedido);

        return redirect()->to($this->voltar($pedido))->with('ok', 'Cancelado: '.$this->nome($pedido).'.');
    }

    public function reenviar(Request $request, PedidoCompra $pedido): RedirectResponse
    {
        $this->exigirAdmin($request->user(), $pedido);
        $this->muro->reenviar($pedido);

        return redirect()->to($this->voltar($pedido))->with('ok', 'Reenviado ao Protheus: '.$this->nome($pedido).'.');
    }

    /**
     * Itens de solicitação de material que já estão num pedido ativo (não cancelado nem reprovado).
     *
     * @param  list<int>  $ids
     * @return array<int, string> id do item => número do pedido
     */
    public static function itensEmPedido(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return PedidoCompraItem::query()->whereIn('solicitacao_material_item_id', $ids)
            ->whereHas('pedido', fn ($q) => $q->whereNotIn('status', ['cancelado', 'reprovado']))
            ->with('pedido:id')->get()
            ->mapWithKeys(fn ($i) => [(int) $i->solicitacao_material_item_id => $i->pedido->numeroFormatado()])->all();
    }

    private function gerar(Request $request, Usuario $u, array $cabecalho, array $itens, string $destino): RedirectResponse
    {
        try {
            $pedido = $this->muro->gerar($cabecalho, $itens, $u);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return redirect()->to($destino)->withInput()->withErrors(['pedido' => 'Não foi possível gravar o pedido na tabela de integração do Protheus. Nada foi gravado; tente de novo em instantes. ('.$e->getMessage().')']);
        }

        return redirect()->to($destino)->with('ok', 'Enviado ao Protheus: '.$this->nome($pedido).', com '.count($itens).' item(ns). Situação: aguardando Protheus.');
    }

    private function regrasCabecalho(): array
    {
        return [
            'centro_custo_origem' => ['required', 'string', 'max:20'],
            'centro_custo_destino' => ['required', 'string', 'max:20'],
            'observacao_pedido' => ['nullable', 'string', 'max:250'],
            'tipo' => ['nullable', Rule::in(array_keys(PedidoCompra::TIPOS))],
        ];
    }

    private function mensagens(): array
    {
        return [
            'centro_custo_origem.required' => 'Escolha o centro de custo de origem do pedido.',
            'centro_custo_destino.required' => 'Escolha o centro de custo de destino do pedido.',
        ];
    }

    private function conferirCentros(array &$dados): void
    {
        foreach (['centro_custo_origem' => 'origem', 'centro_custo_destino' => 'destino'] as $campo => $nome) {
            if (! AcessoOs::centroCustoExiste($dados[$campo])) {
                throw ValidationException::withMessages([$campo => "Escolha o centro de custo de {$nome} da lista."]);
            }
        }
        $dados['observacao'] = $dados['observacao_pedido'] ?? null;
        $dados['tipo'] = ($dados['tipo'] ?? null) ?: 'pedido';
    }

    private function exigirAdmin(Usuario $u, PedidoCompra $pedido): void
    {
        abort_unless(AcessoOs::ehAdminDa($u, $pedido->unidade), 403, 'Só o administrador da unidade mexe no pedido ou na solicitação de compra.');
    }

    /** "PC-000001 (pedido de compra)" / "SC-000002 (solicitação de compra)". */
    private function nome(PedidoCompra $pedido): string
    {
        return $pedido->numeroFormatado().' ('.mb_strtolower($pedido->rotuloTipo()).')';
    }

    private function voltar(PedidoCompra $pedido): string
    {
        return ($pedido->ordem_servico_id ? route('os.ordens.show', $pedido->ordem_servico_id)
            : route('os.materiais-solicitacao.show', $pedido->solicitacao_material_id)).'#pedidos-compra';
    }
}
