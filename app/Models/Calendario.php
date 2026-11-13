<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\TemUnidade;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Calendário anual de dias úteis de uma unidade. Padrão: segunda a sexta úteis; as exceções (feriados, pontes,
 * folgas, compensações, dias úteis extras) ficam em calendario_dias. Máquinas e mantenedores usam um calendário.
 */
class Calendario extends Model
{
    use Auditavel;
    use TemUnidade;

    /** Tipos de dia (exceções). "util" diz se o dia é trabalhado. */
    public const TIPOS = [
        'feriado' => ['rotulo' => 'Feriado', 'util' => false],
        'ponte' => ['rotulo' => 'Ponte (emenda)', 'util' => false],
        'folga' => ['rotulo' => 'Folga / não útil', 'util' => false],
        'compensacao' => ['rotulo' => 'Compensação (trabalhado)', 'util' => true],
        'util' => ['rotulo' => 'Dia útil extra', 'util' => true],
    ];

    protected $table = 'calendarios';

    protected $fillable = ['unidade', 'nome', 'ano', 'descricao', 'ativo'];

    protected function casts(): array
    {
        return ['ano' => 'integer', 'ativo' => 'boolean'];
    }

    public function dias(): HasMany
    {
        return $this->hasMany(CalendarioDia::class);
    }

    public function maquinas(): HasMany
    {
        return $this->hasMany(Maquina::class);
    }

    /** "Padrão Manutenção 2027". */
    public function titulo(): string
    {
        return $this->nome.' '.$this->ano;
    }
}
