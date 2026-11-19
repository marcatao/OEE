<?php

namespace App\Http\Controllers;

use App\Models\FaltaProduto;
use App\Models\FiltroUsuario;
use App\Models\Sincronizacao;
use App\Services\FaltaProdutos\FaltaProdutos;
use App\Services\FaltaProdutos\FiltroFaltaProdutos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Produção › Falta de produtos: as faltas da ZB5 (cópia em faltas_produto) com a situação da produção de cada produto
 * (OP, máquina direcionada, apontamento real produzindo) e painéis. Filtros como nos outros relatórios: ao mudar, a
 * tela busca só o resultado (?parcial=1); o usuário salva filtros com nome (filtros_usuarios) e marca um como padrão.
 */
class FaltaProdutoController extends Controller
{
    public function index(Request $request, FaltaProdutos $faltas): View
    {
        $usuario = $request->user();
        $salvos = FiltroUsuario::daTela($usuario, FiltroFaltaProdutos::TELA)->orderBy('nome')->get();

        // Filtro escolhido na lista > filtro na URL > filtro padrão do usuário > em aberto, sem limite de data.
        $escolhido = $request->filled('filtro') ? $salvos->firstWhere('id', (int) $request->query('filtro')) : null;
        if (! $escolhido && ! $request->hasAny(FiltroFaltaProdutos::PARAMETROS)) {
            $escolhido = $salvos->firstWhere('padrao', true);
        }
        $filtro = $escolhido ? FiltroFaltaProdutos::doSalvo($escolhido->filtros) : FiltroFaltaProdutos::daRequisicao($request);

        $dados = $faltas->montar($filtro, max(1, (int) $request->query('page', 1)), route('falta-produtos.index')) + [
            'filtro' => $filtro,
            'ultimaSincronizacao' => Sincronizacao::where('chave', 'faltas_produto')->where('situacao', 'sucesso')
                ->latest('iniciado_em')->value('iniciado_em'),
        ];
        if ($request->boolean('parcial')) {
            return view('producao.falta-produtos._resultado', $dados);
        }

        return view('producao.falta-produtos.index', $dados + [
            'salvos' => $salvos,
            'escolhido' => $escolhido,
            'statusOpcoes' => FaltaProduto::STATUS,
            'producaoOpcoes' => FaltaProdutos::PRODUCAO,
        ]);
    }

    /** Salva (ou atualiza, se já existir com o mesmo nome) o filtro atual do usuário. */
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
        $filtro = FiltroFaltaProdutos::daRequisicao($request);
        $salvo = DB::transaction(function () use ($usuario, $dados, $filtro) {
            if (! empty($dados['padrao'])) {
                FiltroUsuario::daTela($usuario, FiltroFaltaProdutos::TELA)->update(['padrao' => false]);
            }

            return FiltroUsuario::updateOrCreate(
                ['usuario_id' => $usuario->id, 'tela' => FiltroFaltaProdutos::TELA, 'nome' => trim($dados['nome'])],
                ['filtros' => $filtro->paraSalvar(), 'padrao' => ! empty($dados['padrao'])],
            );
        });

        return redirect()->route('falta-produtos.index', ['filtro' => $salvo->id])->with('ok', "Filtro \"{$salvo->nome}\" salvo.");
    }

    /** Exclui um filtro salvo do próprio usuário. */
    public function excluirFiltro(Request $request, FiltroUsuario $filtro): RedirectResponse
    {
        abort_unless($filtro->usuario_id === $request->user()->id && $filtro->tela === FiltroFaltaProdutos::TELA, 404);
        $nome = $filtro->nome;
        $filtro->delete();

        return redirect()->route('falta-produtos.index')->with('ok', "Filtro \"{$nome}\" excluído.");
    }
}
