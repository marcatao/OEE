<?php

namespace App\Services;

use App\Models\ApontamentoEstado;
use App\Models\OrdemProducao;

/**
 * Status de fácil visualização da OP, calculado pelo portal a partir do Protheus e dos apontamentos:
 *
 * Quem diz se a OP está aberta ou fechada é o C2_DATRF (data real de fim): preenchido = fechada (a data é o
 * encerramento); vazio = aberta, e então o portal detalha em aberta, andamento ou parada.
 *
 * 1. fechada   — C2_DATRF preenchido;
 * 2. parada    — suspensa no Protheus (C2_STATUS = 'U') ou o apontamento EM ABERTO da OP no portal não é "produzindo";
 * 3. andamento — o apontamento em aberto é "produzindo", ou já tem produção no Protheus (C2_QUJE > 0) ou apontamentos;
 * 4. aberta    — nenhum dos casos acima.
 */
class StatusOrdemProducao
{
    public function calcular(OrdemProducao $op): string
    {
        if (trim((string) $op->c2_datrf) !== '') {
            return 'fechada';
        }

        $emAberto = $op->exists
            ? ApontamentoEstado::with('legenda')->where('ordem_producao_id', $op->id)->whereNull('fim')->latest('inicio')->first()
            : null;

        if (trim((string) $op->c2_status) === 'U' || ($emAberto && $emAberto->legenda?->tipo !== 'produzindo')) {
            return 'parada';
        }

        if ($emAberto || (float) $op->c2_quje > 0
            || ($op->exists && ApontamentoEstado::where('ordem_producao_id', $op->id)->exists())) {
            return 'andamento';
        }

        return 'aberta';
    }

    /** Recalcula e grava (só se mudou). Devolve true se mudou. */
    public function atualizar(?OrdemProducao $op): bool
    {
        if (! $op) {
            return false;
        }
        $novo = $this->calcular($op);
        if ($novo === $op->status) {
            return false;
        }
        $op->forceFill(['status' => $novo, 'status_em' => now()])->save();

        return true;
    }
}
