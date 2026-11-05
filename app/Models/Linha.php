<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Linha extends Model
{
    use Auditavel;

    protected $table = 'linhas';

    protected $fillable = ['planta_id', 'codigo', 'nome', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function planta(): BelongsTo
    {
        return $this->belongsTo(Planta::class);
    }

    public function maquinas(): HasMany
    {
        return $this->hasMany(Maquina::class);
    }
}
