<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Apontamento extends Model
{
    use Auditavel;

    protected $table = 'apontamentos';

    protected $fillable = ['maquina_id', 'turno_id', 'produto_id', 'inicio', 'fim', 'ciclo_ideal_s', 'total_produzido', 'pecas_boas'];

    protected function casts(): array
    {
        return ['inicio' => 'datetime', 'fim' => 'datetime', 'ciclo_ideal_s' => 'float'];
    }

    public function maquina(): BelongsTo
    {
        return $this->belongsTo(Maquina::class);
    }
}
