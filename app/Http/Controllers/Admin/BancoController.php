<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sincronizacao;
use App\Services\InfoBanco;
use App\Services\Protheus\SincronizadorProtheus;
use App\Support\Cronometro;
use Illuminate\Http\JsonResponse;
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
            'php' => self::infoPhp(),
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
                $mensagens[] = "{$nome}: {$r->lidos} lidos no Protheus, {$r->novos} novos, {$r->atualizados} atualizados"
                    .($r->excluidos ? ", {$r->excluidos} excluídos no Protheus" : '').'.';
            } else {
                $mensagens[] = "{$nome}: falhou — {$r->mensagem}";
                $erro = true;
            }
        }

        return redirect()->route('admin.banco')->with($erro ? 'erro' : 'ok', 'Sincronização com o Protheus. '.implode(' ', $mensagens));
    }

    /**
     * Uma etapa da sincronização pelo botão. Sem "id": só abre a execução da tabela e devolve o id na hora (se já houver
     * uma em andamento, devolve essa, para a tela continuar dela em vez de dar erro). Com "id": processa a partir de onde
     * parou por no máximo protheus.segundos_por_etapa segundos. A tela chama de novo até "terminou".
     */
    public function etapa(Request $request, SincronizadorProtheus $sincronizador): JsonResponse
    {
        $dados = $request->validate([
            'tabela' => ['required', Rule::in(array_keys($sincronizador->itens()))],
            'id' => ['nullable', 'integer'],
        ]);

        $retomada = false;
        $inicio = microtime(true);
        Cronometro::zerar();
        if (isset($dados['id'])) {
            $registro = Sincronizacao::where('chave', $dados['tabela'])->findOrFail($dados['id']);
            if ($registro->situacao === 'executando') {
                $registro = $sincronizador->continuar($registro, $this->segundosPorEtapa());
            }
        } else {
            $sincronizador->encerrarInterrompidas();
            $registro = Sincronizacao::where('chave', $dados['tabela'])->where('situacao', 'executando')->latest('iniciado_em')->first();
            $retomada = $registro !== null;
            $registro ??= $sincronizador->iniciar($dados['tabela'], 'manual', $request->user());
        }

        return response()->json([
            'id' => $registro->id,
            'tabela' => $registro->chave,
            'descricao' => $sincronizador->itens()[$registro->chave]['descricao'],
            'situacao' => $registro->situacao,
            'terminou' => $registro->situacao !== 'executando',
            'retomada' => $retomada,
            'lidos' => (int) $registro->lidos,
            'novos' => (int) $registro->novos,
            'atualizados' => (int) $registro->atualizados,
            'excluidos' => (int) $registro->excluidos,
            'mensagem' => $registro->mensagem,
            'segundos' => round(microtime(true) - $inicio, 1),
            'tempos' => Cronometro::resumo(), // onde a etapa gastou o tempo (Protheus, portal, status, SB9...)
        ]);
    }

    /**
     * Tempo de trabalho de cada etapa. Pede ao PHP um limite folgado para esta requisição (o padrão do PHP é 30 s e a
     * etapa morria no meio de um lote); se o servidor não deixar, trabalha 12 s a menos que o limite em vigor.
     */
    private function segundosPorEtapa(): int
    {
        $segundos = max(5, (int) config('protheus.segundos_por_etapa', 25));
        $limite = (int) ini_get('max_execution_time');
        if ($limite > 0 && $limite < $segundos + 45 && ! @set_time_limit($segundos + 45)) {
            $segundos = max(5, min($segundos, $limite - 12));
        }

        return $segundos;
    }

    /** Configuração do PHP que está rodando o portal (o php.ini carregado e os limites), sem expor o phpinfo(). */
    public static function infoPhp(): array
    {
        return [
            'Versão' => PHP_VERSION.' ('.PHP_SAPI.')',
            'php.ini carregado' => php_ini_loaded_file() ?: 'nenhum (valores padrão do PHP)',
            'Tempo limite (max_execution_time)' => ((int) ini_get('max_execution_time')).' s',
            'Memória (memory_limit)' => (string) ini_get('memory_limit'),
        ];
    }
}
