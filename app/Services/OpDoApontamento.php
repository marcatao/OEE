<?php

namespace App\Services;

use App\Models\ApontamentoEstado;
use App\Models\OrdemProducao;
use Illuminate\Validation\ValidationException;

/**
 * Altera a OP de UM apontamento real (Timeline › "INFORMAR OP" e app › detalhe do apontamento): só OPs abertas
 * (não fechadas no Protheus). O apontamento mais recente leva a OP também para a máquina; num antigo, a máquina só
 * recebe a OP se estiver sem nenhuma (SituacaoMaquina::informarOp). Peças e cavidades são refeitas pelo evento
 * "saved" do apontamento; o status das duas OPs (a nova e a anterior) é recalculado.
 */
class OpDoApontamento
{
    public function __construct(private SituacaoMaquina $situacao, private StatusOrdemProducao $status) {}

    public function alterar(ApontamentoEstado $apontamento, string $numero, string $campo = 'ordem_producao'): OrdemProducao
    {
        $op = OrdemProducao::apontaveis()->where('numero', trim($numero))->orderBy('status')->first();
        if (! $op) {
            throw ValidationException::withMessages([$campo => 'Ordem de produção não encontrada ou fechada no Protheus.']);
        }
        $anterior = $apontamento->ordem_producao_id;
        $apontamento->update(['ordem_producao_id' => $op->id]);
        $this->situacao->informarOp($apontamento);
        foreach (array_unique(array_filter([(int) $op->id, (int) $anterior])) as $id) {
            $this->status->atualizar(OrdemProducao::find($id));
        }

        return $op;
    }
}
