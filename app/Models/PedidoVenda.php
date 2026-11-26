<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item de pedido de venda do Protheus (SC6) com os dados do cabeçalho (SC5) e o nome do cliente (SA1), numa linha
 * só. Só a procedure dbo.sp_sincronizar_pedidos_venda grava aqui. situacao: aberto | parcial | faturado | eliminado.
 */
class PedidoVenda extends Model
{
    public $timestamps = false;

    protected $table = 'pedidos_venda';

    protected $guarded = [];

    public const SITUACOES = [
        'aberto' => 'Em aberto',
        'parcial' => 'Faturado em parte',
        'faturado' => 'Faturado',
        'eliminado' => 'Resíduo eliminado',
    ];

    protected function casts(): array
    {
        return ['c6_qtdven' => 'float', 'c6_prcven' => 'float', 'c6_valor' => 'float', 'c6_qtdent' => 'float',
            'c5_recno' => 'integer', 'r_e_c_n_o_' => 'integer', 'sincronizado_em' => 'datetime'];
    }

    /** Quanto do item ainda falta entregar (0 se faturado ou eliminado). */
    /** Cadastro do cliente (SA1), ligado pelas procedures pelo C5_CLIENTE + C5_LOJACLI. */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function saldo(): float
    {
        return in_array($this->situacao, ['faturado', 'eliminado'], true) ? 0.0 : max(0.0, $this->c6_qtdven - $this->c6_qtdent);
    }
}
