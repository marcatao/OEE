<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Exceção de um dia num calendário (feriado, ponte, folga, compensação, dia útil extra). */
class CalendarioDia extends Model
{
    use Auditavel;

    protected $table = 'calendario_dias';

    protected $fillable = ['calendario_id', 'data', 'tipo', 'descricao'];

    protected function casts(): array
    {
        return ['data' => 'date'];
    }

    public function calendario(): BelongsTo
    {
        return $this->belongsTo(Calendario::class);
    }

    public function util(): bool
    {
        return Calendario::TIPOS[$this->tipo]['util'] ?? false;
    }
}
