<?php

namespace App\Models\Manutencao;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Item do checklist de uma OS (cópia do item do plano, com a resposta do mantenedor). */
class ChecklistOs extends Model
{
    public const RESPOSTAS = ['ok' => 'OK', 'nok' => 'Não OK', 'na' => 'Não se aplica'];

    public $timestamps = false;

    protected $table = 'os_checklist';

    protected $fillable = ['ordem_servico_id', 'plano_item_id', 'ordem', 'descricao', 'resposta', 'observacao',
        'respondido_por_id', 'respondido_em'];

    protected $attributes = ['ordem' => 0];

    protected function casts(): array
    {
        return ['ordem' => 'integer', 'respondido_em' => 'datetime'];
    }

    public function ordemServico(): BelongsTo
    {
        return $this->belongsTo(OrdemServico::class, 'ordem_servico_id');
    }

    public function planoItem(): BelongsTo
    {
        return $this->belongsTo(PlanoPreventivaItem::class, 'plano_item_id');
    }

    public function respondidoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'respondido_por_id');
    }
}
