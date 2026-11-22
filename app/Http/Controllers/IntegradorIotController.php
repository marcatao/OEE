<?php

namespace App\Http\Controllers;

use App\Models\ApontamentoEstado;
use App\Services\Manutencao\SsAutomatica;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * O Integrador MQTT grava os apontamentos reais do IoT direto no banco (e já atualiza a situação da máquina e o status
 * das OPs). Depois de cada gravação ele chama esta rota com os apontamentos CRIADOS ou com a LEGENDA TROCADA, e o
 * portal aplica a SS automática com a mesma regra das telas (App\Services\Manutencao\SsAutomatica). Idempotente:
 * mandar de novo o mesmo apontamento não abre outra SS.
 */
class IntegradorIotController extends Controller
{
    public function processar(Request $request, SsAutomatica $ss): JsonResponse
    {
        $dados = $request->validate([
            'apontamentos' => ['required', 'array', 'max:'.(int) config('oee.iot.max_apontamentos', 500)],
            'apontamentos.*' => ['integer', 'min:1'],
        ]);
        $ids = array_values(array_unique(array_map('intval', $dados['apontamentos'])));

        $resultado = [];
        foreach (ApontamentoEstado::whereIntegerInRaw('id', $ids)->orderBy('inicio')->get() as $ap) {
            try {
                $aberta = $ss->aoApontar($ap);
                $resultado[] = ['id' => (int) $ap->id, 'ss' => $aberta?->numeroFormatado(),
                    'mensagem' => SsAutomatica::mensagem((int) $ap->id)];
            } catch (Throwable $e) {
                Log::error('Integrador IoT: falha ao processar o apontamento', ['apontamento' => $ap->id, 'erro' => $e->getMessage()]);
                $resultado[] = ['id' => (int) $ap->id, 'ss' => null, 'mensagem' => 'Falha no portal: '.$e->getMessage()];
            }
        }
        $encontrados = array_column($resultado, 'id');

        return response()->json([
            'processados' => count($resultado),
            'nao_encontrados' => array_values(array_diff($ids, $encontrados)),
            'apontamentos' => $resultado,
        ]);
    }
}
