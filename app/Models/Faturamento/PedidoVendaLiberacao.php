<?php

namespace App\Models\Faturamento;

use Illuminate\Database\Eloquent\Model;

/**
 * Liberação do pedido de venda: cópia da SC9 do Protheus. "extras" (JSON) guarda os campos do WMS/expedição usados nas
 * regras das etapas. Só a sincronização grava aqui.
 */
class PedidoVendaLiberacao extends Model
{
    public $timestamps = false;

    protected $table = 'pedidos_venda_liberacoes';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['c9_qtdlib' => 'float', 'c9_prcven' => 'float', 'r_e_c_n_o_' => 'integer', 'sincronizado_em' => 'datetime'];
    }

    public function extras(): array
    {
        return json_decode((string) $this->extras, true) ?: [];
    }
}
