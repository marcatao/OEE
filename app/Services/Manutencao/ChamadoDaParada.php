<?php

namespace App\Services\Manutencao;

use App\Models\Manutencao\SolicitacaoServico;
use App\Models\Maquina;

/**
 * SS/OS aberta pelo apontamento de uma legenda que "abre SS" (SsAutomatica) para a máquina que está PARADA agora:
 * a da parada atual (aberta desde que a máquina parou). Aprovada, mostra a OS. Vai no lugar da meta na Timeline e no
 * aplicativo, para o operador acompanhar o desfecho. Máquina produzindo (ou sem SS da parada) = null.
 */
final class ChamadoDaParada
{
    /** @return array{tipo: string, id: int, numero: string, status: string, rotulo: string}|null */
    public static function da(Maquina $maquina): ?array
    {
        if ($maquina->situacao !== 'parada') {
            return null;
        }
        $ss = SolicitacaoServico::where('maquina_id', $maquina->id)->where('automatica', true)
            ->when($maquina->situacao_desde, fn ($q) => $q->where('criado_em', '>=', $maquina->situacao_desde->copy()->subMinute()))
            ->with('ordemServico')->orderByDesc('id')->first();
        if (! $ss) {
            return null;
        }
        $os = $ss->ordemServico;

        return $os
            ? ['tipo' => 'os', 'id' => $os->id, 'numero' => $os->numeroFormatado(), 'status' => $os->status, 'rotulo' => $os->rotuloStatus()]
            : ['tipo' => 'ss', 'id' => $ss->id, 'numero' => $ss->numeroFormatado(), 'status' => $ss->status, 'rotulo' => $ss->rotuloStatus()];
    }
}
