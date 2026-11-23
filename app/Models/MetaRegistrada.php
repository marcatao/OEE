<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Meta de produção registrada (histórico): a meta (pç/min) de um produto + revisão a partir de vigente_desde.
 * Gravada só por App\Services\MetaProducao quando a meta calculada da estrutura muda; nunca se altera depois.
 */
class MetaRegistrada extends Model
{
    public $timestamps = false;

    protected $table = 'metas_producao';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['horas' => 'float', 'base' => 'float', 'por_minuto' => 'float',
            'vigente_desde' => 'datetime', 'registrado_em' => 'datetime'];
    }
}
