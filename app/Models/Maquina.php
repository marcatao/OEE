<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Maquina extends Model
{
    use Auditavel;

    protected $table = 'maquinas';

    protected $fillable = ['linha_id', 'codigo', 'nome', 'fabricante', 'modelo', 'ciclo_ideal_s', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'ciclo_ideal_s' => 'float'];
    }

    public function linha(): BelongsTo
    {
        return $this->belongsTo(Linha::class);
    }
}
