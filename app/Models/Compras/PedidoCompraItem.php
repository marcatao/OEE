<?php

namespace App\Models\Compras;

use App\Models\Concerns\Auditavel;
use App\Models\Manutencao\SolicitacaoMaterialItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Item do pedido de compra (vai para o Protheus como uma linha do pedido, C7_ITEM = item). */
class PedidoCompraItem extends Model
{
    use Auditavel;

    protected $table = 'pedidos_compra_itens';

    protected $fillable = ['pedido_compra_id', 'solicitacao_material_item_id', 'item', 'produto_codigo', 'produto_descricao', 'um',
        'quantidade', 'observacao'];

    protected function casts(): array
    {
        return ['quantidade' => 'decimal:4', 'valor_unitario' => 'decimal:4', 'valor_total' => 'decimal:2', 'quantidade_entregue' => 'decimal:4'];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(PedidoCompra::class, 'pedido_compra_id');
    }

    public function itemSolicitacao(): BelongsTo
    {
        return $this->belongsTo(SolicitacaoMaterialItem::class, 'solicitacao_material_item_id');
    }
}
