<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\TemUnidade;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Ferramenta (SH4 do Protheus, tabela MISTA). Os campos h4_* vêm do Protheus a cada sincronização e não se editam no
 * portal. Do portal: h4_qtdcavativas (cavidades ativas, nunca maior que h4_qtdcav; nasce igual) e a unidade.
 */
class Ferramenta extends Model
{
    use Auditavel;
    use TemUnidade;

    protected $table = 'ferramentas';

    /** Só os campos do portal podem ser gravados pela tela. */
    protected $fillable = ['h4_qtdcavativas', 'unidade', 'disponivel'];

    protected function casts(): array
    {
        return ['h4_quant' => 'float', 'h4_vidauti' => 'float', 'h4_qtdcav' => 'integer', 'h4_qtdcavativas' => 'integer', 'disponivel' => 'boolean',
            'r_e_c_n_o_' => 'integer', 'sincronizado_em' => 'datetime'];
    }

    public function scopeValidas(Builder $q): Builder
    {
        return $q->where('d_e_l_e_t_', '<>', '*');
    }

    public function codigo(): string
    {
        return rtrim($this->h4_codigo);
    }

    /** As cavidades ativas passaram das cavidades do Protheus (o Protheus diminuiu depois): precisa ajustar no portal. */
    public function cavidadesAcima(): bool
    {
        return $this->h4_qtdcavativas > $this->h4_qtdcav;
    }
}
