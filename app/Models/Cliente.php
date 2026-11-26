<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cliente do Protheus (cópia da SA1010 do servidor vinculado ALUMBRAPRD). Só a procedure dbo.sp_sincronizar_clientes
 * grava aqui; ela também liga os pedidos de venda ao cliente (pedidos_venda.cliente_id).
 */
class Cliente extends Model
{
    public $timestamps = false;

    protected $table = 'clientes';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['r_e_c_n_o_' => 'integer', 'sincronizado_em' => 'datetime'];
    }

    public function pedidos(): HasMany
    {
        return $this->hasMany(PedidoVenda::class);
    }

    /** "000123/01 — RAZÃO SOCIAL". */
    public function rotulo(): string
    {
        return rtrim($this->a1_cod).'/'.rtrim($this->a1_loja).' — '.rtrim($this->a1_nome);
    }

    public function bloqueado(): bool
    {
        return trim((string) $this->a1_msblql) === '1';
    }
}
