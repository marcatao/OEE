<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Meta e peças de uma hora do turno, por máquina e OP (metas_producao_horas). Gravada só por App\Services\MetaPorHora.
 */
class MetaHora extends Model
{
    public $timestamps = false;

    protected $table = 'metas_producao_horas';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['hora_inicio' => 'datetime', 'dia' => 'date', 'meta_por_minuto' => 'float', 'meta' => 'float',
            'segundos' => 'integer', 'pecas' => 'integer', 'atualizado_em' => 'datetime'];
    }

    public function ordemProducao()
    {
        return $this->belongsTo(OrdemProducao::class);
    }
}
