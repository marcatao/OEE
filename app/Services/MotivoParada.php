<?php

namespace App\Services;

use App\Models\ApontamentoEstado;
use App\Models\Legenda;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Parada "sem motivo" (legenda config('oee.app.legenda_sem_motivo'), 98, gravada pelo IoT): o operador troca a legenda
 * SÓ daquele apontamento por uma legenda de parada (tipos config('oee.app.tipos_legenda_motivo')). Regra única da
 * Timeline do portal e do aplicativo.
 */
class MotivoParada
{
    public function __construct(private SituacaoMaquina $situacao, private StatusOrdemProducao $status) {}

    public static function codigoSemMotivo(): string
    {
        return (string) config('oee.app.legenda_sem_motivo', '98');
    }

    public static function ehSemMotivo(?Legenda $legenda): bool
    {
        return $legenda !== null && (string) $legenda->codigo === self::codigoSemMotivo();
    }

    /** Legendas que podem ser o motivo (ativas, do tipo parada, nunca a própria "sem motivo"). */
    public function legendas(): Collection
    {
        return Legenda::where('ativo', true)->whereIn('tipo', (array) config('oee.app.tipos_legenda_motivo', ['parada']))
            ->where('codigo', '<>', self::codigoSemMotivo())->orderBy('ordem')->orderBy('codigo')->get();
    }

    public function informar(ApontamentoEstado $apontamento, int $legendaId): void
    {
        if (! self::ehSemMotivo($apontamento->legenda)) {
            throw ValidationException::withMessages(['legenda_id' => 'Este apontamento já tem motivo.']);
        }
        $legenda = $this->legendas()->firstWhere('id', $legendaId);
        if (! $legenda) {
            throw ValidationException::withMessages(['legenda_id' => 'Escolha uma legenda de parada.']);
        }
        $apontamento->update(['legenda_id' => $legenda->id]);
        $this->situacao->aplicar($apontamento->fresh());
        $this->status->atualizar($apontamento->ordemProducao);
    }
}
