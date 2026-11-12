<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Roteiro de operações do Protheus (cópia da SG2): operações de fabricação do produto, com centro de trabalho
 * (g2_ctrab = código da máquina, SHB), recurso, ferramenta (g2_ferram = SH4) e tempos. Só a sincronização grava aqui.
 */
class RoteiroOperacao extends Model
{
    public const TIPOS_OPERACAO = ['1' => 'Normal', '2' => 'Tempo fixo', '3' => 'Ilimitada', '4' => 'Tempo mínimo'];

    public $timestamps = false;

    protected $table = 'roteiros_operacao';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['g2_setup' => 'float', 'g2_tempad' => 'float', 'g2_lotepad' => 'float', 'g2_maoobra' => 'float',
            'r_e_c_n_o_' => 'integer', 'sincronizado_em' => 'datetime'];
    }

    public function scopeValidas(Builder $q): Builder
    {
        return $q->where('d_e_l_e_t_', '<>', '*');
    }

    public function rotuloTipo(): string
    {
        return self::TIPOS_OPERACAO[trim((string) $this->g2_tpoper)] ?? (trim((string) $this->g2_tpoper) ?: '-');
    }
}
