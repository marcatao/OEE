<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sincronizacao;
use App\Services\InfoBanco;
use App\Services\Protheus\SincronizadorProtheus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Administração > Banco de dados: conexão do portal, conexão com o Protheus, tabelas (do sistema ou sincronizadas
 * do Protheus) e o botão de sincronizar.
 */
class BancoController extends Controller
{
    public function __invoke(InfoBanco $banco, SincronizadorProtheus $sincronizador): View
    {
        [$ok, $mensagem] = $banco->testar();
        $temHistorico = $ok && Schema::hasTable('sincronizacoes');

        return view('admin.banco', [
            'conexao' => $banco->descricao(),
            'ok' => $ok,
            'mensagem' => $mensagem,
            'tabelas' => $ok ? $banco->contagens() : [],
            'origens' => $banco->origens(),
            'conexaoProtheus' => $banco->descricao('protheus'),
            'sincronizacoes' => $sincronizador->itens(),
            'ultimas' => $temHistorico ? $sincronizador->ultimas() : [],
            'historico' => $temHistorico ? Sincronizacao::with('usuario')->latest('iniciado_em')->latest('id')->limit(10)->get() : collect(),
            'agenda' => config('protheus.agenda'),
        ]);
    }

    /** Botão "Sincronizar com o Protheus": todas as tabelas, ou só a escolhida. */
    public function sincronizar(Request $request, SincronizadorProtheus $sincronizador): RedirectResponse
    {
        $dados = $request->validate(['tabela' => ['nullable', Rule::in(array_keys($sincronizador->itens()))]]);

        $resultados = isset($dados['tabela'])
            ? [$dados['tabela'] => $sincronizador->sincronizar($dados['tabela'], 'manual', $request->user())]
            : $sincronizador->sincronizarTudo('manual', $request->user());

        $mensagens = [];
        $erro = false;
        foreach ($resultados as $chave => $r) {
            $nome = $sincronizador->itens()[$chave]['descricao'];
            if ($r->situacao === 'sucesso') {
                $mensagens[] = "{$nome}: {$r->lidos} lidos no Protheus, {$r->novos} novos, {$r->atualizados} atualizados.";
            } else {
                $mensagens[] = "{$nome}: falhou — {$r->mensagem}";
                $erro = true;
            }
        }

        return redirect()->route('admin.banco')->with($erro ? 'erro' : 'ok', 'Sincronização com o Protheus. '.implode(' ', $mensagens));
    }
}
