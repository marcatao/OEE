<?php

namespace App\Models\Manutencao;

use App\Models\ApontamentoEstado;
use App\Models\Concerns\Auditavel;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Solicitação de Serviço (SS): aberta por qualquer setor, analisada pela manutenção (aprovada vira OS, rejeitada volta
 * ao solicitante com o motivo). Sem o escopo global de unidade: o solicitante sempre vê as próprias (ver
 * visiveisPara()).
 */
class SolicitacaoServico extends Model
{
    use Auditavel;

    public const TIPOS = [
        'corretiva' => 'Corretiva',
        'preventiva' => 'Preventiva',
        'servico_geral' => 'Serviço geral',
    ];

    public const PRIORIDADES = [
        'baixa' => 'Baixa',
        'media' => 'Média',
        'alta' => 'Alta',
        'urgente' => 'Urgente',
    ];

    public const STATUS = [
        'pendente' => 'Aguardando análise',
        'aprovada' => 'Aprovada (virou OS)',
        'rejeitada' => 'Rejeitada',
        'cancelada' => 'Cancelada',
    ];

    protected $table = 'solicitacoes_servico';

    protected $fillable = ['unidade', 'solicitante_id', 'tipo', 'maquina_id', 'area_executante_id', 'centro_custo',
        'centro_custo_solicitante', 'prioridade', 'maquina_parada', 'descricao', 'status', 'analisado_por_id',
        'analisado_em', 'motivo_rejeicao', 'apontamento_estado_id', 'ordem_producao_id'];

    protected $attributes = ['prioridade' => 'media', 'maquina_parada' => false, 'status' => 'pendente'];

    protected function casts(): array
    {
        return ['maquina_parada' => 'boolean', 'analisado_em' => 'datetime'];
    }

    /** SS que o usuário enxerga: as da(s) unidade(s) dele e, sempre, as que ele mesmo abriu. */
    public function scopeVisiveisPara(Builder $consulta, Usuario $usuario): void
    {
        $visiveis = $usuario->unidadesVisiveis();
        if ($visiveis === null) {
            return;
        }
        $consulta->where(fn (Builder $q) => $q->whereIn($this->qualifyColumn('unidade'), $visiveis)
            ->orWhere($this->qualifyColumn('solicitante_id'), $usuario->id));
    }

    public static function unidadePara(?AreaExecutante $area, ?Maquina $maquina): string
    {
        return OrdemServico::unidadePara($area, $maquina);
    }

    public function numeroFormatado(): string
    {
        return 'SS '.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function rotuloTipo(): string
    {
        return self::TIPOS[$this->tipo] ?? (string) $this->tipo;
    }

    public function rotuloPrioridade(): string
    {
        return self::PRIORIDADES[$this->prioridade] ?? (string) $this->prioridade;
    }

    public function rotuloStatus(): string
    {
        return self::STATUS[$this->status] ?? (string) $this->status;
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'solicitante_id');
    }

    public function maquina(): BelongsTo
    {
        return $this->belongsTo(Maquina::class, 'maquina_id')->withoutGlobalScope('unidade');
    }

    public function areaExecutante(): BelongsTo
    {
        return $this->belongsTo(AreaExecutante::class, 'area_executante_id')->withoutGlobalScope('unidade');
    }

    public function analisadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'analisado_por_id');
    }

    public function apontamentoEstado(): BelongsTo
    {
        return $this->belongsTo(ApontamentoEstado::class, 'apontamento_estado_id');
    }

    public function ordemProducao(): BelongsTo
    {
        return $this->belongsTo(OrdemProducao::class, 'ordem_producao_id');
    }

    /** OS gerada na aprovação. */
    public function ordemServico(): HasOne
    {
        return $this->hasOne(OrdemServico::class, 'solicitacao_id');
    }

    public function historico(): HasMany
    {
        return $this->hasMany(HistoricoOs::class, 'solicitacao_id');
    }
}
