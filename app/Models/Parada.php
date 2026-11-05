<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Parada extends Model
{
    use Auditavel;

    protected $table = 'paradas';

    protected $fillable = ['maquina_id', 'motivo_id', 'inicio', 'fim', 'observacao'];

    protected function casts(): array
    {
        return ['inicio' => 'datetime', 'fim' => 'datetime'];
    }

    public function maquina(): BelongsTo
    {
        return $this->belongsTo(Maquina::class);
    }

    public function motivo(): BelongsTo
    {
        return $this->belongsTo(MotivoParada::class, 'motivo_id');
    }
}
