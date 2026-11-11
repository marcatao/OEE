<?php

namespace App\Models\Manutencao;

use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Material requisitado para a OS (almoxarifado → compras → entrega à manutenção). */
class MaterialOs extends Model
{
    use Auditavel;

    public const STATUS = [
        'requisitado' => 'Requisitado ao almoxarifado',
        'disponivel' => 'Disponível no estoque',
        'em_compra' => 'Em compra',
        'recebido' => 'Recebido',
        'entregue' => 'Entregue à manutenção',
        'cancelado' => 'Cancelado',
    ];

    /** Status em que o material ainda segura a OS em "aguardando material". */
    public const STATUS_PENDENTES = ['requisitado', 'disponivel', 'em_compra', 'recebido'];

    protected $table = 'os_materiais';

    protected $fillable = ['ordem_servico_id', 'produto_codigo', 'descricao', 'quantidade', 'um', 'status',
        'solicitacao_compra', 'custo_unitario', 'observacao'];

    protected $attributes = ['status' => 'requisitado'];

    protected function casts(): array
    {
        return ['quantidade' => 'decimal:4', 'custo_unitario' => 'decimal:4'];
    }

    public function rotuloStatus(): string
    {
        return self::STATUS[$this->status] ?? (string) $this->status;
    }

    public function ordemServico(): BelongsTo
    {
        return $this->belongsTo(OrdemServico::class, 'ordem_servico_id');
    }
}
