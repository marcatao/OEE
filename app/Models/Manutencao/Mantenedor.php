<?php

namespace App\Models\Manutencao;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\TemUnidade;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mão de obra da manutenção. A jornada (HH:MM no fuso local, dias ISO 1 = segunda ... 7 = domingo) separa as horas
 * normais das extras e dá as horas disponíveis no período (ver App\Services\Manutencao\HorasManutencao).
 */
class Mantenedor extends Model
{
    use Auditavel;
    use TemUnidade;

    public const DIAS_SEMANA = ['1' => 'Seg', '2' => 'Ter', '3' => 'Qua', '4' => 'Qui', '5' => 'Sex', '6' => 'Sáb', '7' => 'Dom'];

    protected $table = 'os_mantenedores';

    protected $fillable = ['unidade', 'usuario_id', 'nome', 'matricula', 'area_executante_id', 'centro_custo',
        'jornada_inicio', 'jornada_fim', 'intervalo_min', 'dias_semana', 'ativo'];

    protected $attributes = ['unidade' => 'manutencao', 'jornada_inicio' => '07:00', 'jornada_fim' => '17:00',
        'intervalo_min' => 60, 'dias_semana' => '12345', 'ativo' => true];

    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'intervalo_min' => 'integer'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function areaExecutante(): BelongsTo
    {
        return $this->belongsTo(AreaExecutante::class, 'area_executante_id')->withoutGlobalScope('unidade');
    }

    public function apontamentos(): HasMany
    {
        return $this->hasMany(ApontamentoHoras::class, 'mantenedor_id');
    }

    /** OS em que é o responsável. */
    public function ordensServico(): HasMany
    {
        return $this->hasMany(OrdemServico::class, 'responsavel_id');
    }

    /** Trabalha no dia da semana ISO (1 = segunda ... 7 = domingo)? */
    public function trabalhaNoDia(int $diaIso): bool
    {
        return str_contains((string) $this->dias_semana, (string) $diaIso);
    }
}
