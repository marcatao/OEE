<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ordem de produção do Protheus (cópia da SC2 do banco Compras). Os campos c2_* só a sincronização grava; o portal
 * calcula o "status" (aberta, andamento, fechada, parada) — ver App\Services\StatusOrdemProducao.
 */
class OrdemProducao extends Model
{
    public $timestamps = false;

    public const STATUS = [
        'aberta' => 'Aberta',
        'andamento' => 'Andamento',
        'parada' => 'Parada',
        'fechada' => 'Fechada',
    ];

    protected $table = 'ordens_producao';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['c2_quant' => 'float', 'c2_quje' => 'float', 'c2_perda' => 'float', 'custo_meta' => 'float', 'r_e_c_n_o_' => 'integer',
            'status_em' => 'datetime', 'sincronizado_em' => 'datetime'];
    }

    public function apontamentos(): HasMany
    {
        return $this->hasMany(ApontamentoEstado::class, 'ordem_producao_id');
    }

    /** Produto da OP (SB1), pelo código; prefere o da mesma filial e, se não houver, o compartilhado. */
    public function produto(): ?ProdutoProtheus
    {
        return ProdutoProtheus::where('b1_cod', $this->c2_produto)
            ->whereIn('b1_filial', array_unique([$this->c2_filial, '']))
            ->orderByDesc('b1_filial')->first();
    }

    /** OPs que ainda podem receber apontamento (não fechadas nem excluídas no Protheus). */
    public function scopeApontaveis(Builder $q): Builder
    {
        return $q->where('status', '<>', 'fechada')->where('d_e_l_e_t_', '<>', '*');
    }

    public function rotuloStatus(): string
    {
        return self::STATUS[$this->status] ?? $this->status;
    }

    /** "20261105" → "05/11/2026". */
    public static function data(?string $aaaammdd): string
    {
        $d = trim((string) $aaaammdd);

        return strlen($d) === 8 ? substr($d, 6, 2).'/'.substr($d, 4, 2).'/'.substr($d, 0, 4) : '-';
    }
}
