<?php

namespace App\Services;

use App\Models\ApontamentoEstado;
use App\Models\Legenda;
use App\Models\Maquina;

/**
 * Situação atual (produzindo | parada) e OP atual da máquina, guardadas no cadastro da máquina (a Timeline lê de lá).
 * - O cadastro da máquina pode informar as duas à mão.
 * - O apontamento real MAIS RECENTE da máquina atualiza as duas: o tipo da legenda decide (produzindo = Produzindo;
 *   parada, planejada ou desligada = Parada) e a OP do apontamento vira a OP da máquina; apontamento sem OP desvincula.
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
        if ($maisRecente !== $apontamento->id) {
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
}
