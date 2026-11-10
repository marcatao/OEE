<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Produto do Protheus (cópia da SB1 do banco Compras). Só a sincronização grava aqui: o portal não edita.
 * Não confundir com a tabela antiga "produtos" (do Sistema OEE).
 */
class ProdutoProtheus extends Model
{
    public $timestamps = false;

    protected $table = 'produtos_protheus';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['r_e_c_n_o_' => 'integer', 'sincronizado_em' => 'datetime'];
    }

    public function codigo(): string
    {
        return rtrim($this->b1_cod);
    }

    public function descricao(): string
    {
        return rtrim($this->b1_desc);
    }

    public function bloqueado(): bool
    {
        return $this->b1_msblql === '1' || $this->d_e_l_e_t_ === '*';
    }
}
