<?php

namespace App\Models\Manutencao;

use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Item do checklist de um plano de preventiva (copiado para os_checklist quando a OS é gerada). */
class PlanoPreventivaItem extends Model
{
    use Auditavel;

    protected $table = 'os_planos_itens';

    protected $fillable = ['plano_preventiva_id', 'ordem', 'descricao', 'ativo'];

    protected $attributes = ['ordem' => 0, 'ativo' => true];

    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'ordem' => 'integer'];
    }

    public function plano(): BelongsTo
    {
        return $this->belongsTo(PlanoPreventiva::class, 'plano_preventiva_id')->withoutGlobalScope('unidade');
    }
}
