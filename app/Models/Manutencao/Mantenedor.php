<?php

namespace App\Models\Manutencao;

use App\Models\Calendario;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\TemUnidade;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    protected $fillable = ['calendario_id', 'unidade', 'usuario_id', 'nome', 'matricula', 'area_executante_id', 'centro_custo',
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

    /** Ferramentas que estão com o mantenedor. */
    public function ferramentas(): HasMany
    {
        return $this->hasMany(Ferramenta::class, 'mantenedor_id')->withoutGlobalScope('unidade');
    }

    /** Áreas executantes que o mantenedor atende (várias; a coluna area_executante_id é a principal). */
    public function areas(): BelongsToMany
    {
        return $this->belongsToMany(AreaExecutante::class, 'os_mantenedor_areas', 'mantenedor_id', 'area_executante_id')
            ->withoutGlobalScope('unidade');
    }

    /** Define as áreas (a primeira vira a principal). */
    public function definirAreas(array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids))));
        $atuais = $this->areas()->pluck('os_areas_executantes.id')->map(fn ($i) => (int) $i)->all();
        if ($remover = array_diff($atuais, $ids)) {
            $this->areas()->detach($remover);
        }
        foreach (array_diff($ids, $atuais) as $id) {
            $this->areas()->attach($id, ['criado_em' => now(), 'criado_por_id' => auth()->id()]);
        }
        $this->forceFill(['area_executante_id' => $ids[0] ?? null])->save();
    }

    /** Nome exibido: o do login (Entra ID), senão o gravado no cadastro. */
    public function nomeExibicao(): string
    {
        return $this->usuario?->nome ?: (string) $this->nome;
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

    /** Calendário de dias úteis do mantenedor (Administração › Calendários). */
    public function calendario(): BelongsTo
    {
        return $this->belongsTo(Calendario::class);
    }
}
