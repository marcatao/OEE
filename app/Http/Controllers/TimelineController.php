<?php

namespace App\Http\Controllers;

use App\Models\FiltroUsuario;
use App\Models\Legenda;
use App\Models\Turno;
use App\Services\Filtros\FiltroTimeline;
use App\Services\MotivoParada;
use App\Services\SituacaoMaquina;
use App\Services\Timeline;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Dashboards > Timeline: estado de cada máquina no período, pintado com a cor da legenda.
 *
 * Filtros: De/Até (data e hora; padrão hoje 01:00–23:59) e plantas, linhas e máquinas (vazio = todas). Ao mudar um
 * filtro, a tela busca só os dados (?parcial=1) e troca a área de resultado, sem recarregar a página. O usuário pode
 * salvar filtros com nome (tabela filtros_usuarios) e marcar um como padrão, aplicado ao abrir a tela.
 */
class TimelineController extends Controller
{
    private const PARAMETROS_DE_FILTRO = ['de', 'ate', 'data', 'plantas', 'linhas', 'maquinas'];

    public function __invoke(Request $request, Timeline $timeline): View
    {
        $usuario = $request->user();
        $salvos = FiltroUsuario::daTela($usuario, FiltroTimeline::TELA)->orderBy('nome')->get();

        // Filtro escolhido na lista > filtro na URL > filtro padrão do usuário > hoje 01:00–23:59.
        $escolhido = $request->filled('filtro') ? $salvos->firstWhere('id', (int) $request->query('filtro')) : null;
        if (! $escolhido && ! $request->hasAny(self::PARAMETROS_DE_FILTRO)) {
            $escolhido = $salvos->firstWhere('padrao', true);
        }
        $filtro = $escolhido ? FiltroTimeline::doSalvo($escolhido->filtros) : FiltroTimeline::daRequisicao($request);

        // OP fechada (ou excluída) no Protheus sai da máquina antes de mostrar a situação.
        app(SituacaoMaquina::class)->desvincularOpsFechadas();

        $dados = $this->resultado($filtro, $timeline);
        if ($request->boolean('parcial')) {
            return view('dashboard.timeline._resultado', $dados);
        }

        return view('dashboard.timeline', $dados + [
            'opcoes' => FiltroTimeline::opcoes(),
            'turnos' => Turno::where('ativo', true)->orderBy('inicio')->get(),
            'salvos' => $salvos,
            'escolhido' => $escolhido,
            'hoje' => FiltroTimeline::padrao(),
            'legendas' => Legenda::where('ativo', true)->orderBy('ordem')->orderBy('codigo')->get(),
            'legendasMotivo' => app(MotivoParada::class)->legendas(),
        ]);
    }

    /** Salva (ou atualiza, se já existir com o mesmo nome) o filtro atual do usuário. */
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
        $filtro = FiltroTimeline::daRequisicao($request);
        $salvo = DB::transaction(function () use ($usuario, $dados, $filtro) {
            if (! empty($dados['padrao'])) {
                FiltroUsuario::daTela($usuario, FiltroTimeline::TELA)->update(['padrao' => false]);
            }

            return FiltroUsuario::updateOrCreate(
                ['usuario_id' => $usuario->id, 'tela' => FiltroTimeline::TELA, 'nome' => trim($dados['nome'])],
                ['filtros' => $filtro->paraSalvar($dados['periodo']), 'padrao' => ! empty($dados['padrao'])],
            );
        });

        return redirect()->route('timeline', ['filtro' => $salvo->id])->with('ok', "Filtro \"{$salvo->nome}\" salvo.");
    }

    /** Exclui um filtro salvo do próprio usuário (ação dele na tela). */
    public function excluirFiltro(Request $request, FiltroUsuario $filtro): RedirectResponse
    {
        abort_unless($filtro->usuario_id === $request->user()->id && $filtro->tela === FiltroTimeline::TELA, 404);
        $nome = $filtro->nome;
        $filtro->delete();

        return redirect()->route('timeline')->with('ok', "Filtro \"{$nome}\" excluído.");
    }

    private function resultado(FiltroTimeline $filtro, Timeline $timeline): array
    {
        $inicio = $filtro->de;
        $fim = $filtro->fimExclusivo();
        $maquinas = $filtro->maquinas()->get();
        $dados = $timeline->montar($maquinas, $inicio->utc(), $fim->utc());
        $agora = now()->getTimestamp();

        return [
            'filtro' => $filtro,
            'inicio' => $inicio,
            'fim' => $fim,
            'maquinas' => $maquinas,
            'linhas' => $dados['linhas'],
            'totais' => $dados['totais'],
            'disponibilidade' => $dados['disponibilidade'],
            'pendentesOp' => $dados['pendentesOp'],
            'pendentes' => $dados['pendentes'],
            'semMotivo' => $dados['semMotivo'],
            'situacoes' => $timeline->situacoes($maquinas),
            'marcas' => $timeline->marcas($inicio->utc(), $fim->utc()),
            'agora' => $agora > $inicio->getTimestamp() && $agora < $fim->getTimestamp()
                ? round(($agora - $inicio->getTimestamp()) / ($fim->getTimestamp() - $inicio->getTimestamp()) * 100, 3) : null,
            'aoVivo' => $agora > $inicio->getTimestamp() && $agora < $fim->getTimestamp(),
            'atualizadoEm' => now()->setTimezone(FiltroTimeline::fuso()),
        ];
    }
}
