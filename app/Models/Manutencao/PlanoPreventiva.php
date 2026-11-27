<?php

namespace App\Models\Manutencao;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\TemUnidade;
use App\Models\Maquina;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plano de preventiva (PAMP): periodicidade semanal, quinzenal ou meses marcados no ano ("meses" = 12 caracteres
 * jan..dez, '1' = tem preventiva no mês: todos = mensal, 4 = trimestral, 1 = anual). O comando
 * os:gerar-preventivas gera as OS com "antecedencia_dias" antes de "proxima_em".
 */
class PlanoPreventiva extends Model
{
    use Auditavel;
    use TemUnidade;

    public const PERIODICIDADES = [
        'semanal' => 'Semanal',
        'quinzenal' => 'Quinzenal',
        'meses' => 'Meses marcados (mensal/trimestral/anual)',
    ];

    protected $table = 'os_planos_preventiva';

    protected $fillable = ['unidade', 'codigo', 'descricao', 'maquina_id', 'area_executante_id', 'responsavel_id',
        'prioridade', 'periodicidade', 'meses', 'dia_mes', 'proxima_em', 'antecedencia_dias', 'duracao_prevista_min', 'ativo'];

    protected $attributes = ['unidade' => 'manutencao', 'prioridade' => 'baixa', 'meses' => '111111111111',
        'dia_mes' => 1, 'antecedencia_dias' => 7, 'ativo' => true];

    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'proxima_em' => 'date', 'dia_mes' => 'integer', 'antecedencia_dias' => 'integer',
            'duracao_prevista_min' => 'integer', 'excluido_em' => 'datetime'];
    }

    /** Excluída pelo botão "Excluir": fica só como histórico (some da lista, não gera OS, não se edita). */
    public function excluida(): bool
    {
        return $this->excluido_em !== null;
    }

    /** Só as não excluídas. */
    public function scopeNaoExcluidas($consulta)
    {
        return $consulta->whereNull('os_planos_preventiva.excluido_em');
    }

    /** Tem preventiva no mês (1 = janeiro ... 12 = dezembro)? Semanal e quinzenal valem para todos os meses. */
    public function temNoMes(int $mes): bool
    {
        if ($mes < 1 || $mes > 12) {
            return false;
        }
        if ($this->periodicidade !== 'meses') {
            return true;
        }

        return substr(str_pad((string) $this->meses, 12, '0'), $mes - 1, 1) === '1';
    }

    public function rotuloPeriodicidade(): string
    {
        return self::PERIODICIDADES[$this->periodicidade] ?? (string) $this->periodicidade;
    }

    public function maquina(): BelongsTo
    {
        return $this->belongsTo(Maquina::class, 'maquina_id')->withoutGlobalScope('unidade');
    }

    public function areaExecutante(): BelongsTo
    {
        return $this->belongsTo(AreaExecutante::class, 'area_executante_id')->withoutGlobalScope('unidade');
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(Mantenedor::class, 'responsavel_id')->withoutGlobalScope('unidade');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(PlanoPreventivaItem::class, 'plano_preventiva_id');
    }

    public function ordensServico(): HasMany
    {
        return $this->hasMany(OrdemServico::class, 'plano_preventiva_id');
    }
}
