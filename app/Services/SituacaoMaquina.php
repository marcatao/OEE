<?php

namespace App\Services;

use App\Models\ApontamentoEstado;
use App\Models\Legenda;
use App\Models\Maquina;
use App\Models\OrdemProducao;

/**
 * Situação atual (produzindo | parada) e OP atual da máquina, guardadas no cadastro da máquina (a Timeline lê de lá).
 * - O cadastro da máquina pode informar as duas à mão.
 * - O apontamento real MAIS RECENTE da máquina atualiza as duas: o tipo da legenda decide (produzindo = Produzindo;
 *   parada, planejada ou desligada = Parada) e a OP do apontamento vira a OP da máquina; apontamento sem OP desvincula.
 * - OP fechada (ou excluída) no Protheus sai da máquina: na sincronização das OPs e ao abrir a Timeline.
 */
class SituacaoMaquina
{
    /** Tipo da legenda → situação da máquina. */
    public static function pelaLegenda(?Legenda $legenda): ?string
    {
        return $legenda === null ? null : ($legenda->tipo === 'produzindo' ? 'produzindo' : 'parada');
    }

    /** Aplica o apontamento na máquina, se ele for o mais recente dela (editar um apontamento antigo não muda nada). */
    public function aplicar(ApontamentoEstado $apontamento): void
    {
        $maisRecente = ApontamentoEstado::where('maquina_id', $apontamento->maquina_id)
            ->orderByDesc('inicio')->orderByDesc('id')->value('id');
        if ((int) $maisRecente !== (int) $apontamento->id) { // (int): no SQL Server os ids chegam como texto
            return;
        }
        $maquina = Maquina::withoutGlobalScopes()->find($apontamento->maquina_id);
        $maquina?->update([
            'situacao' => self::pelaLegenda($apontamento->legenda()->first()),
            'situacao_desde' => $apontamento->inicio,
            'situacao_legenda_id' => $apontamento->legenda_id,
            'ordem_producao_id' => $apontamento->ordem_producao_id,
        ]);
    }

    /** Depois de excluir um apontamento: a máquina volta a seguir o apontamento mais recente que sobrou. */
    public function recalcular(int $maquinaId): void
    {
        $ultimo = ApontamentoEstado::where('maquina_id', $maquinaId)->orderByDesc('inicio')->orderByDesc('id')->first();
        if ($ultimo) {
            $this->aplicar($ultimo);
        } else {
            Maquina::withoutGlobalScopes()->whereKey($maquinaId)->update(['situacao_legenda_id' => null]);
        }
    }

    /**
     * Desvincula das máquinas as OPs fechadas (ou excluídas) no Protheus. Os apontamentos continuam com a OP deles.
     *
     * @param  list<int>|null  $ops  só estas OPs (ex.: o lote da sincronização); null = todas
     * @return int quantas máquinas foram desvinculadas
     */
    public function desvincularOpsFechadas(?array $ops = null): int
    {
        $fechadas = OrdemProducao::query()
            ->when($ops !== null, fn ($q) => $q->whereIn('id', $ops ?: [0]))
            ->where(fn ($q) => $q->where('status', 'fechada')->orWhere('d_e_l_e_t_', '*'))
            ->select('id');

        return Maquina::withoutGlobalScopes()->whereIn('ordem_producao_id', $fechadas)->update(['ordem_producao_id' => null]);
    }

    /**
     * OP informada depois num apontamento (ex.: Timeline › "Informar OP"): a máquina passa a ter essa OP se o apontamento
     * é o mais recente dela ou se ela está sem OP.
     */
    public function informarOp(ApontamentoEstado $apontamento): void
    {
        $maisRecente = ApontamentoEstado::where('maquina_id', $apontamento->maquina_id)
            ->orderByDesc('inicio')->orderByDesc('id')->value('id');
        if ((int) $maisRecente === (int) $apontamento->id) {
            $this->aplicar($apontamento);

            return;
        }
        Maquina::withoutGlobalScopes()->whereKey($apontamento->maquina_id)->whereNull('ordem_producao_id')
            ->update(['ordem_producao_id' => $apontamento->ordem_producao_id]);
    }
}
