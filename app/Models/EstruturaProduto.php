<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Estrutura de produto do Protheus (cópia da SG1): cada linha é um componente (g1_comp) do produto pai (g1_cod).
 * Só a sincronização grava aqui.
 */
class EstruturaProduto extends Model
{
    public $timestamps = false;

    protected $table = 'estruturas_produto';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['g1_quant' => 'float', 'g1_perda' => 'float', 'r_e_c_n_o_' => 'integer', 'sincronizado_em' => 'datetime'];
    }

    /** Não excluídas no Protheus. */
    public function scopeValidas(Builder $q): Builder
    {
        return $q->where('d_e_l_e_t_', '<>', '*');
    }

    /** Vigentes na data (AAAAMMDD): início vazio ou até a data, fim vazio ou a partir da data. */
    public function scopeVigentes(Builder $q, string $aaaammdd): Builder
    {
        return $q->where(fn ($w) => $w->where('g1_ini', '')->orWhere('g1_ini', '<=', $aaaammdd))
            ->where(fn ($w) => $w->where('g1_fim', '')->orWhere('g1_fim', '>=', $aaaammdd));
    }
}
