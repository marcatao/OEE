<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function dispositivos(): HasMany
    {
        return $this->hasMany(DispositivoIot::class);
    }

    /** "Linha 1 · Planta Matriz" para listas e cabeçalhos. */
    public function localizacao(): string
    {
        return collect([$this->linha?->nome, $this->linha?->planta?->nome])->filter()->join(' · ');
    }
}
