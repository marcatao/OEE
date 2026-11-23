<?php

namespace App\Http\Controllers;

use App\Models\ApontamentoEstado;
use App\Services\HorarioApontamento;
use App\Services\Manutencao\ExclusaoApontamento;
use App\Services\Manutencao\SsAutomatica;
use App\Services\PecasProduzidas;
use App\Services\SituacaoMaquina;
use App\Services\StatusOrdemProducao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * O Integrador MQTT grava os apontamentos reais do IoT direto no banco (e já atualiza a situação da máquina e o status
 * das OPs). Depois de cada gravação ele chama esta rota com os apontamentos CRIADOS ou com a LEGENDA TROCADA, e o
 * portal aplica a SS automática com a mesma regra das telas (App\Services\Manutencao\SsAutomatica). Idempotente:
 * mandar de novo o mesmo apontamento não abre outra SS.
 * Também recebe "producao" (apontamentos de produção com ciclos novos: o portal calcula as peças = ciclos ×
 * cavidades, App\\Services\\PecasProduzidas) e "ops" (OPs afetadas: soma a quantidade real).
 */
class IntegradorIotController extends Controller
{
    public function processar(Request $request, SsAutomatica $ss, PecasProduzidas $pecas): JsonResponse
    {
        $max = (int) config('oee.iot.max_apontamentos', 500);
        $dados = $request->validate([
            'apontamentos' => ['array', 'max:'.$max, 'required_without_all:producao,ops,excluir'],
            'apontamentos.*' => ['integer', 'min:1'],
            'producao' => ['array', 'max:'.$max],
            'producao.*' => ['integer', 'min:1'],
            'ops' => ['array', 'max:'.$max],
            'ops.*' => ['integer', 'min:1'],
            'excluir' => ['array', 'max:'.$max],
            'excluir.*' => ['integer', 'min:1'],
        ]);
        $ids = array_values(array_unique(array_map('intval', $dados['apontamentos'] ?? [])));

        // 00) Apontamentos do IoT que o integrador quis excluir (trecho corrigido) e o banco recusou porque abriram SS/OS
        //     automática: o portal exclui com o chamado junto (SS pendente / OS sem atendimento canceladas). Só os do IoT.
        $excluidos = [];
        $excluir = array_values(array_unique(array_map('intval', $dados['excluir'] ?? [])));
        foreach ($excluir ? ApontamentoEstado::whereIntegerInRaw('id', $excluir)->where('observacao', 'like', '[IoT]%')->get() : [] as $ap) {
            try {
                $maisRecente = (int) ApontamentoEstado::where('maquina_id', $ap->maquina_id)->orderByDesc('inicio')->orderByDesc('id')->value('id') === (int) $ap->id;
                $feito = app(ExclusaoApontamento::class)->excluir($ap, SsAutomatica::usuarioIot(),
                    'Trecho corrigido pelo IoT (Integrador MQTT): o apontamento de origem foi excluído.');
                app(StatusOrdemProducao::class)->atualizar($ap->ordemProducao);
                if ($maisRecente) {
                    app(SituacaoMaquina::class)->recalcular((int) $ap->maquina_id);
                }
                $excluidos[] = ['id' => (int) $ap->id, 'chamados' => $feito];
            } catch (Throwable $e) {
                Log::error('Integrador IoT: falha ao excluir o apontamento', ['apontamento' => $ap->id, 'erro' => $e->getMessage()]);
            }
        }

        // 0) Só o apontamento mais recente de cada máquina fica em aberto (o integrador grava direto no banco, sem os
        //    eventos do portal): encerra os abertos que ficaram para trás antes de tudo (uma consulta agrupada).
        try {
            HorarioApontamento::conferirAbertos();
        } catch (Throwable $e) {
            Log::error('Integrador IoT: falha ao encerrar apontamentos em aberto', ['erro' => $e->getMessage()]);
        }

        // 1) SS automática dos apontamentos criados ou com a legenda trocada.
        $resultado = [];
        foreach ($ids ? ApontamentoEstado::whereIntegerInRaw('id', $ids)->orderBy('inicio')->get() : [] as $ap) {
            try {
                $aberta = $ss->aoApontar($ap);
                $resultado[] = ['id' => (int) $ap->id, 'ss' => $aberta?->numeroFormatado(),
                    'mensagem' => SsAutomatica::mensagem((int) $ap->id)];
            } catch (Throwable $e) {
                Log::error('Integrador IoT: falha ao processar o apontamento', ['apontamento' => $ap->id, 'erro' => $e->getMessage()]);
                $resultado[] = ['id' => (int) $ap->id, 'ss' => null, 'mensagem' => 'Falha no portal (detalhes no log do portal).'];
            }
        }

        // 2) Peças dos apontamentos de produção com ciclos novos e quantidade real das OPs afetadas.
        $producao = array_values(array_unique(array_map('intval', $dados['producao'] ?? [])));
        $ops = array_map('intval', $dados['ops'] ?? []);
        $comPecas = [];
        foreach ($producao ? ApontamentoEstado::whereIntegerInRaw('id', $producao)->get() : [] as $ap) {
            try {
                $ops[] = (int) $pecas->aplicar($ap);
                $comPecas[] = ['id' => (int) $ap->id, 'ciclos' => $ap->ciclos, 'cavidades' => $ap->cavidades, 'pecas' => $ap->pecas];
            } catch (Throwable $e) {
                Log::error('Integrador IoT: falha ao contar as peças', ['apontamento' => $ap->id, 'erro' => $e->getMessage()]);
            }
        }
        $ops = array_values(array_unique(array_filter($ops)));
        foreach ($ops as $op) {
            $pecas->recalcularOp($op);
        }

        return response()->json([
            'processados' => count($resultado),
            'nao_encontrados' => array_values(array_diff($ids, array_column($resultado, 'id'))),
            'apontamentos' => $resultado,
            'producao' => $comPecas,
            'ops' => $ops,
            'excluidos' => $excluidos,
        ]);
    }
}
