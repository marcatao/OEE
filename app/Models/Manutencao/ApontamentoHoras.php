<?php

namespace App\Models\Manutencao;

use App\Models\Concerns\Auditavel;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apontamento de horas do mantenedor numa OS. Sem "fim" = rodando (no SQL Server, no máximo um aberto por
 * mantenedor). Minutos normais × extras são calculados pela jornada ao fechar e podem ser corrigidos à mão.
 * criado_por_id = quem lançou.
 */
class ApontamentoHoras extends Model
{
    use Auditavel;

    protected $table = 'os_apontamentos_horas';

    protected $fillable = ['ordem_servico_id', 'mantenedor_id', 'inicio', 'fim', 'minutos_normais', 'minutos_extras', 'descricao'];

    protected function casts(): array
    {
        return ['inicio' => 'datetime', 'fim' => 'datetime', 'minutos_normais' => 'integer', 'minutos_extras' => 'integer'];
    }

    public function aberto(): bool
    {
        return $this->fim === null;
    }

    /** Normais + extras; aberto = duração até agora; fechado sem minutos gravados = duração início → fim. */
    public function minutosTotais(): int
    {
        if ($this->fim === null) {
            return $this->inicio ? max(0, (int) floor($this->inicio->diffInMinutes(now()))) : 0;
        }
        if ($this->minutos_normais === null && $this->minutos_extras === null) {
            return $this->inicio ? max(0, (int) floor($this->inicio->diffInMinutes($this->fim))) : 0;
        }

        return (int) $this->minutos_normais + (int) $this->minutos_extras;
    }

    public function ordemServico(): BelongsTo
    {
        return $this->belongsTo(OrdemServico::class, 'ordem_servico_id');
    }

    public function mantenedor(): BelongsTo
    {
        return $this->belongsTo(Mantenedor::class, 'mantenedor_id')->withoutGlobalScope('unidade');
    }

    /** Usuário que lançou o apontamento (= criado_por_id). */
    public function lancadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'criado_por_id');
    }
}
