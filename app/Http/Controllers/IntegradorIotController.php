<?php

namespace App\Http\Controllers;

use App\Models\ApontamentoEstado;
use App\Services\Manutencao\SsAutomatica;
use App\Services\PecasProduzidas;
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
            'apontamentos' => ['array', 'max:'.$max, 'required_without_all:producao,ops'],
            'apontamentos.*' => ['integer', 'min:1'],
            'producao' => ['array', 'max:'.$max],
            'producao.*' => ['integer', 'min:1'],
            'ops' => ['array', 'max:'.$max],
            'ops.*' => ['integer', 'min:1'],
        ]);
        $ids = array_values(array_unique(array_map('intval', $dados['apontamentos'] ?? [])));

        // 1) SS automática dos apontamentos criados ou com a legenda trocada.
        $resultado = [];
        foreach ($ids ? ApontamentoEstado::whereIntegerInRaw('id', $ids)->orderBy('inicio')->get() : [] as $ap) {
            try {
                $aberta = $ss->aoApontar($ap);
                $resultado[] = ['id' => (int) $ap->id, 'ss' => $aberta?->numeroFormatado(),
                    'mensagem' => SsAutomatica::mensagem((int) $ap->id)];
            } catch (Throwable $e) {
                Log::error('Integrador IoT: falha ao processar o apontamento', ['apontamento' => $ap->id, 'erro' => $e->getMessage()]);
                $resultado[] = ['id' => (int) $ap->id, 'ss' => null, 'mensagem' => 'Falha no portal: '.$e->getMessage()];
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
        ]);
    }
}
