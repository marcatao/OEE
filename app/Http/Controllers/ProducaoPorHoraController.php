<?php

namespace App\Http\Controllers;

use App\Models\FiltroUsuario;
use App\Services\Filtros\FiltroProducaoHora;
use App\Services\Filtros\FiltroTimeline;
use App\Services\ProducaoPorHora;
use App\Services\SituacaoMaquina;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Dashboards › Produção por hora: máquina a máquina, as peças contadas pelo IoT em cada hora do turno (o gráfico do
 * app), o total do turno, a OP e o produto atuais e as OPs que produziram no turno. Meta em pç/min: em breve.
 *
 * Mesmo padrão da Timeline: filtros (dia, plantas, linhas e máquinas) que começam escondidos e trocam só a área de
 * resultado (?parcial=1), atualização sozinha a cada minuto no dia de hoje e filtros salvos (filtros_usuarios).
 */
class ProducaoPorHoraController extends Controller
{
    private const PARAMETROS_DE_FILTRO = ['dia', 'plantas', 'linhas', 'maquinas'];

    public function __invoke(Request $request, ProducaoPorHora $producao): View
    {
        $salvos = FiltroUsuario::daTela($request->user(), FiltroProducaoHora::TELA)->orderBy('nome')->get();
        $escolhido = $request->filled('filtro') ? $salvos->firstWhere('id', (int) $request->query('filtro')) : null;
        if (! $escolhido && ! $request->hasAny(self::PARAMETROS_DE_FILTRO)) {
            $escolhido = $salvos->firstWhere('padrao', true);
        }
        $filtro = $escolhido ? FiltroProducaoHora::doSalvo($escolhido->filtros) : FiltroProducaoHora::daRequisicao($request);

        // OP fechada no Protheus sai da máquina antes de mostrar a OP atual (como na Timeline; não a cada minuto).
        if (! $request->boolean('parcial')) {
            app(SituacaoMaquina::class)->desvincularOpsFechadas();
        }

        $dados = ['filtro' => $filtro] + $producao->montar($filtro->maquinas()->get(), $filtro->dia);
        if ($request->boolean('parcial')) {
            return view('dashboard.producao-hora._resultado', $dados);
        }

        return view('dashboard.producao-hora', $dados + [
            'opcoes' => FiltroTimeline::opcoes(),
            'salvos' => $salvos,
            'escolhido' => $escolhido,
            'hoje' => FiltroProducaoHora::hoje(),
        ]);
    }

    /** Salva (ou atualiza, com o mesmo nome) o filtro atual do usuário. */
    public function salvarFiltro(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:80'],
            'periodo' => ['required', Rule::in(['hoje', 'fixo'])],
            'padrao' => ['nullable', 'boolean'],
        ], [
            'nome.required' => 'Dê um nome ao filtro.',
            'nome.max' => 'O nome do filtro pode ter até 80 caracteres.',
        ]);
        $usuario = $request->user();
        $filtro = FiltroProducaoHora::daRequisicao($request);
        $salvo = DB::transaction(function () use ($usuario, $dados, $filtro) {
            if (! empty($dados['padrao'])) {
                FiltroUsuario::daTela($usuario, FiltroProducaoHora::TELA)->update(['padrao' => false]);
            }

            return FiltroUsuario::updateOrCreate(
                ['usuario_id' => $usuario->id, 'tela' => FiltroProducaoHora::TELA, 'nome' => trim($dados['nome'])],
                ['filtros' => $filtro->paraSalvar($dados['periodo']), 'padrao' => ! empty($dados['padrao'])],
            );
        });

        return redirect()->route('producao-hora', ['filtro' => $salvo->id])->with('ok', "Filtro \"{$salvo->nome}\" salvo.");
    }

    /** Exclui um filtro salvo do próprio usuário. */
    public function excluirFiltro(Request $request, FiltroUsuario $filtro): RedirectResponse
    {
        abort_unless((int) $filtro->usuario_id === (int) $request->user()->id && $filtro->tela === FiltroProducaoHora::TELA, 404);
        $nome = $filtro->nome;
        $filtro->delete();

        return redirect()->route('producao-hora')->with('ok', "Filtro \"{$nome}\" excluído.");
    }
}
