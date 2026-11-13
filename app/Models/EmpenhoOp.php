<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Empenho de uma OP no Protheus (cópia da SD4): componente reservado para a OP. d4_qtdeori = empenho original,
 * d4_quant = saldo ainda empenhado (o que falta baixar). Só a sincronização grava aqui.
 */
class EmpenhoOp extends Model
{
    public $timestamps = false;

    protected $table = 'empenhos_op';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['d4_qtdeori' => 'float', 'd4_quant' => 'float', 'r_e_c_n_o_' => 'integer', 'sincronizado_em' => 'datetime'];
    }

    /** Não excluídos no Protheus. */
    public function scopeValidos(Builder $q): Builder
    {
        return $q->where('d_e_l_e_t_', '<>', '*');
    }

    /** Quanto do empenho já foi baixado (original − saldo). */
    public function baixado(): float
    {
        return max(0.0, $this->d4_qtdeori - $this->d4_quant);
    }

    /** Fração já baixada (0 a 1); null sem empenho original. */
    public function atendido(): ?float
    {
        return $this->d4_qtdeori > 0 ? min(1.0, $this->baixado() / $this->d4_qtdeori) : null;
    }
}
