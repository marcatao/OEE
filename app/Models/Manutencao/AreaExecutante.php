<?php

namespace App\Models\Manutencao;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\TemUnidade;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Área executante da manutenção (elétrica, mecânica, TI...): para onde a SS/OS é distribuída. */
class AreaExecutante extends Model
{
    use Auditavel;
    use TemUnidade;

    protected $table = 'os_areas_executantes';

    protected $fillable = ['unidade', 'codigo', 'nome', 'ativo', 'meta_atendimento_min', 'meta_reparo_min'];

    /** Minutos => "17:01" (horas:minutos; passa de 24 h se preciso). Null = "". */
    public static function hhmm(?int $minutos): string
    {
        return $minutos === null ? '' : sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
    }

    /** "17:01" (ou "17") => minutos; vazio = null; inválido = false. */
    public static function minutosDe(?string $texto): int|false|null
    {
        $texto = trim((string) $texto);
        if ($texto === '') {
            return null;
        }
        if (! preg_match('/^(\d{1,4})(?::([0-5]\d))?$/', $texto, $m)) {
            return false;
        }

        return (int) $m[1] * 60 + (int) ($m[2] ?? 0);
    }

    protected $attributes = ['unidade' => 'manutencao', 'ativo' => true];

    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'meta_atendimento_min' => 'integer', 'meta_reparo_min' => 'integer'];
    }

    public function mantenedores(): HasMany
    {
        return $this->hasMany(Mantenedor::class, 'area_executante_id');
    }

    public function ordensServico(): HasMany
    {
        return $this->hasMany(OrdemServico::class, 'area_executante_id');
    }
}
