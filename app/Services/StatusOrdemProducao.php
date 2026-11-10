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
        if (! $op->exists) {
            return self::regra($op->c2_datrf, $op->c2_status, $op->c2_quje, null, false);
        }

        $emAberto = ApontamentoEstado::with('legenda')->where('ordem_producao_id', $op->id)->whereNull('fim')->latest('inicio')->first();

        return self::regra($op->c2_datrf, $op->c2_status, $op->c2_quje, $emAberto?->legenda?->tipo ?? ($emAberto ? '' : null),
            $emAberto !== null || ApontamentoEstado::where('ordem_producao_id', $op->id)->exists());
    }

    /**
     * A regra, sem consultar o banco.
     *
     * @param  ?string  $tipoEmAberto  tipo da legenda do apontamento em aberto da OP (null = nenhum em aberto)
     */
    public static function regra(?string $datrf, ?string $c2Status, mixed $quje, ?string $tipoEmAberto, bool $temApontamento): string
    {
        if (trim((string) $datrf) !== '') {
            return 'fechada';
        }
        if (trim((string) $c2Status) === 'U' || ($tipoEmAberto !== null && $tipoEmAberto !== 'produzindo')) {
            return 'parada';
        }
        if ($tipoEmAberto !== null || (float) $quje > 0 || $temApontamento) {
            return 'andamento';
        }

        return 'aberta';
    }

    /**
     * Recalcula o status de várias OPs de uma vez (usado a cada lote da sincronização): 3 consultas por lote, em vez
     * de várias por OP, e grava só as que mudaram. Devolve os ids cujo status mudou.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function atualizarLote(array $ids): array
    {
        $mudaram = [];
        foreach (array_chunk(array_values(array_unique($ids)), 1000) as $bloco) {
            $ops = OrdemProducao::whereIn('id', $bloco)->get(['id', 'c2_datrf', 'c2_status', 'c2_quje', 'status']);

            // Apontamento em aberto mais recente de cada OP (tipo da legenda) e quais OPs têm algum apontamento.
            $emAberto = [];
            foreach (ApontamentoEstado::query()->join('legendas', 'legendas.id', '=', 'apontamentos_estado.legenda_id')
                ->whereIn('apontamentos_estado.ordem_producao_id', $bloco)->whereNull('apontamentos_estado.fim')
                ->orderBy('apontamentos_estado.inicio')
                ->get(['apontamentos_estado.ordem_producao_id as op', 'legendas.tipo as tipo']) as $a) {
                $emAberto[$a->op] = $a->tipo; // o último (mais recente) prevalece
            }
            $comApontamento = array_flip(ApontamentoEstado::whereIn('ordem_producao_id', $bloco)
                ->distinct()->pluck('ordem_producao_id')->all());

            $porStatus = [];
            foreach ($ops as $op) {
                $novo = self::regra($op->c2_datrf, $op->c2_status, $op->c2_quje, $emAberto[$op->id] ?? null, isset($comApontamento[$op->id]));
                if ($novo !== $op->status) {
                    $porStatus[$novo][] = $op->id;
                }
            }
            foreach ($porStatus as $status => $idsDoStatus) {
                OrdemProducao::whereIn('id', $idsDoStatus)->update(['status' => $status, 'status_em' => now()]);
                array_push($mudaram, ...$idsDoStatus);
            }
        }

        return $mudaram;
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
