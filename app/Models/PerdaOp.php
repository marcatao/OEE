<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Perda apontada numa OP (portal ou aplicativo): quantidade de peças e peso pelo PU do produto (B1_PESO).
 * Excluir não apaga: grava quem e quando (excluido_por_id / excluido_em) e a perda sai do total da OP.
 */
class PerdaOp extends Model
{
    use Auditavel;

    protected $table = 'perdas_op';

    protected $fillable = ['ordem_producao_id', 'maquina_id', 'dia', 'quantidade', 'peso', 'peso_unitario', 'origem', 'observacao'];

    protected function casts(): array
    {
        return ['dia' => 'date', 'quantidade' => 'float', 'peso' => 'float', 'peso_unitario' => 'float', 'excluido_em' => 'datetime'];
    }

    public function excluida(): bool
    {
        return $this->excluido_em !== null;
    }

    public function ordemProducao(): BelongsTo
    {
        return $this->belongsTo(OrdemProducao::class);
    }

    public function maquina(): BelongsTo
    {
        return $this->belongsTo(Maquina::class)->withoutGlobalScopes();
    }

    public function excluidoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'excluido_por_id');
    }
}
