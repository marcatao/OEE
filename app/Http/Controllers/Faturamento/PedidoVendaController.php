<?php

namespace App\Http\Controllers\Faturamento;

use App\Http\Controllers\Controller;
use App\Models\FiltroUsuario;
use App\Models\Sincronizacao;
use App\Services\Faturamento\EtapasPedidoVenda;
use App\Services\Faturamento\FiltroPedidosVenda;
use App\Services\Faturamento\PedidosVendaDashboard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Faturamento › Pedidos de venda: cartões por etapa (clicáveis, filtram a lista) e lista dos pedidos do Protheus
 * (cópia da SC5/SC6/SC9). Filtros como nos outros relatórios: ao mudar, a tela busca só o resultado (?parcial=1); o
 * usuário salva filtros com nome (filtros_usuarios) e marca um como padrão. O mês sempre abre no atual.
 */
class PedidoVendaController extends Controller
{
    public function index(Request $request, PedidosVendaDashboard $dashboard): View
    {
        $usuario = $request->user();
        $salvos = FiltroUsuario::daTela($usuario, FiltroPedidosVenda::TELA)->orderBy('nome')->get();

        // Filtro escolhido na lista > filtro na URL > filtro padrão do usuário > padrão (mês atual, todas as etapas).
        $escolhido = $request->filled('filtro') ? $salvos->firstWhere('id', (int) $request->query('filtro')) : null;
        if (! $escolhido && ! $request->hasAny(FiltroPedidosVenda::PARAMETROS)) {
            $escolhido = $salvos->firstWhere('padrao', true);
        }
        $filtro = $escolhido ? FiltroPedidosVenda::doSalvo($escolhido->filtros, $request->query('mes'))
            : FiltroPedidosVenda::daRequisicao($request);

        $dados = $dashboard->montar($filtro, max(1, (int) $request->query('page', 1)), route('faturamento.pedidos.index')) + [
            'filtro' => $filtro,
            'ultimaSincronizacao' => Sincronizacao::where('chave', 'pedidos_venda')->where('situacao', 'sucesso')
                ->latest('iniciado_em')->value('iniciado_em'),
        ];
        if ($request->boolean('parcial')) {
            return view('faturamento.pedidos._resultado', $dados);
        }

        return view('faturamento.pedidos.index', $dados + [
            'salvos' => $salvos,
            'escolhido' => $escolhido,
            'etapas' => EtapasPedidoVenda::rotulos(),
        ]);
    }

    /** Salva (ou atualiza, se já existir com o mesmo nome) o filtro atual do usuário. O mês não vai junto. */
    public function salvarFiltro(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:80'],
            'padrao' => ['nullable', 'boolean'],
        ], [
            'nome.required' => 'Dê um nome ao filtro.',
            'nome.max' => 'O nome do filtro pode ter até 80 caracteres.',
        ]);

        $usuario = $request->user();
        $filtro = FiltroPedidosVenda::daRequisicao($request);
        $salvo = DB::transaction(function () use ($usuario, $dados, $filtro) {
            if (! empty($dados['padrao'])) {
                FiltroUsuario::daTela($usuario, FiltroPedidosVenda::TELA)->update(['padrao' => false]);
            }

            return FiltroUsuario::updateOrCreate(
                ['usuario_id' => $usuario->id, 'tela' => FiltroPedidosVenda::TELA, 'nome' => trim($dados['nome'])],
                ['filtros' => $filtro->paraSalvar(), 'padrao' => ! empty($dados['padrao'])],
            );
        });

        return redirect()->route('faturamento.pedidos.index', ['filtro' => $salvo->id])->with('ok', "Filtro \"{$salvo->nome}\" salvo.");
    }

    /** Exclui um filtro salvo do próprio usuário. */
    public function excluirFiltro(Request $request, FiltroUsuario $filtro): RedirectResponse
    {
        abort_unless((int) $filtro->usuario_id === (int) $request->user()->id && $filtro->tela === FiltroPedidosVenda::TELA, 404);
        $nome = $filtro->nome;
        $filtro->delete();

        return redirect()->route('faturamento.pedidos.index')->with('ok', "Filtro \"{$nome}\" excluído.");
    }
}
