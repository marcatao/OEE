<?php

namespace App\Models\Manutencao;

use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Atributo livre de um equipamento (ex.: IP = 10.0.5.20). A unidade é a do equipamento. */
class AtributoFerramenta extends Model
{
    use Auditavel;

    protected $table = 'os_ferramentas_atributos';

    protected $fillable = ['ferramenta_id', 'nome', 'valor', 'ordem'];

    protected function casts(): array
    {
        return ['ordem' => 'integer'];
    }

    public function ferramenta(): BelongsTo
    {
        return $this->belongsTo(Ferramenta::class)->withoutGlobalScope('unidade');
    }
}
