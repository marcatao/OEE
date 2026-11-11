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

    protected $fillable = ['unidade', 'codigo', 'nome', 'ativo'];

    protected $attributes = ['unidade' => 'manutencao', 'ativo' => true];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
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
