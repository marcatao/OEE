<?php

namespace App\Models\Compras;

use App\Models\Concerns\Auditavel;
use App\Models\Manutencao\OrdemServico;
use App\Models\Manutencao\SolicitacaoMaterial;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pedido de compra pedido no portal (de uma solicitação de material ou de uma OS) e gerado no Protheus pela tabela muro
 * (App\Services\Compras\PedidoCompraMuro). Situação, número, aprovação, conclusão e valores vêm do Protheus.
 */
class PedidoCompra extends Model
{
    use Auditavel;

    public const STATUS = [
        'aguardando_protheus' => 'Aguardando Protheus',
        'aguardando_aprovacao' => 'Integrado — aguardando aprovação',
        'aprovado' => 'Aprovado',
        'concluido' => 'Concluído',
        'reprovado' => 'Reprovado',
        'erro' => 'Erro no Protheus',
        'cancelado' => 'Cancelado',
    ];

    /** Situações que não mudam mais: não são lidas de novo na tabela muro. */
    public const FINAIS = ['concluido', 'reprovado', 'cancelado'];

    /** Situações em que o pedido não conta no custo (não vai ser comprado). */
    public const SEM_CUSTO = ['reprovado', 'cancelado', 'erro'];

    protected $table = 'pedidos_compra';

    protected $fillable = ['unidade', 'solicitacao_material_id', 'ordem_servico_id', 'filial', 'centro_custo_origem',
        'centro_custo_destino', 'observacao', 'status'];

    protected function casts(): array
    {
        return ['criado_em' => 'datetime', 'integrado_em' => 'datetime', 'aprovado_em' => 'datetime', 'concluido_em' => 'datetime',
            'consultado_em' => 'datetime', 'valor_total' => 'decimal:2'];
    }

    public function itens(): HasMany
    {
        return $this->hasMany(PedidoCompraItem::class, 'pedido_compra_id');
    }

    public function solicitacaoMaterial(): BelongsTo
    {
        return $this->belongsTo(SolicitacaoMaterial::class, 'solicitacao_material_id');
    }

    public function ordemServico(): BelongsTo
    {
        return $this->belongsTo(OrdemServico::class, 'ordem_servico_id');
    }

    public function numeroFormatado(): string
    {
        return 'PC-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function rotuloStatus(): string
    {
        return self::STATUS[$this->status] ?? (string) $this->status;
    }

    public function estaFinal(): bool
    {
        return in_array($this->status, self::FINAIS, true);
    }

    /** Ainda dá para cancelar no portal: o Protheus não pegou o pedido (ou deu erro). */
    public function podeCancelar(): bool
    {
        return in_array($this->status, ['aguardando_protheus', 'erro'], true);
    }

    /** Valor que conta no custo: o do Protheus (zero enquanto não houver ou se não vai ser comprado). */
    public function valorCusto(): float
    {
        return in_array($this->status, self::SEM_CUSTO, true) ? 0.0 : (float) $this->valor_total;
    }
}
