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
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Ordem de Serviço (OS): nasce da SS aprovada (copia os dados dela) ou do plano de preventiva. Status: aberta →
 * em_execucao ⇄ aguardando_material → concluida → encerrada (aceite do solicitante) | cancelada. Toda troca de status
 * grava os_historico. total_minutos = soma dos apontamentos (recalculada ao gravar, ver recalcularTotalMinutos()).
 * Sem o escopo global de unidade: o solicitante sempre vê as próprias (ver visiveisPara()).
 */
class OrdemServico extends Model
{
    use Auditavel;

    public const TIPOS = SolicitacaoServico::TIPOS;

    public const PRIORIDADES = SolicitacaoServico::PRIORIDADES;

    public const STATUS = [
        'aberta' => 'Aberta',
        'em_execucao' => 'Em execução',
        'aguardando_material' => 'Aguardando material',
        'concluida' => 'Concluída (aguardando avaliação)',
        'encerrada' => 'Encerrada',
        'cancelada' => 'Cancelada',
    ];

    /** Status em que a OS ainda está na fila da manutenção (backlog). */
    public const STATUS_ABERTOS = ['aberta', 'em_execucao', 'aguardando_material'];

    protected $table = 'ordens_servico';

    protected $fillable = ['avaliacao', 'titulo', 'unidade', 'solicitacao_id', 'plano_preventiva_id', 'prevista_para', 'tipo', 'maquina_id',
        'area_executante_id', 'centro_custo', 'centro_custo_solicitante', 'prioridade', 'maquina_parada', 'descricao',
        'solicitante_id', 'aprovado_por_id', 'responsavel_id', 'status', 'iniciada_em', 'concluida_em', 'solucao',
        'aceite_por_id', 'aceite_em', 'aceite_observacao', 'cancelamento_motivo', 'ordem_producao_id',
        'apontamento_estado_id', 'total_minutos'];

    protected $attributes = ['prioridade' => 'media', 'maquina_parada' => false, 'status' => 'aberta', 'total_minutos' => 0];

    protected function casts(): array
    {
        return ['maquina_parada' => 'boolean', 'prevista_para' => 'date', 'iniciada_em' => 'datetime',
            'concluida_em' => 'datetime', 'aceite_em' => 'datetime', 'total_minutos' => 'integer'];
    }

    /** OS que o usuário enxerga: as da(s) unidade(s) dele e, sempre, as que ele mesmo solicitou. */
    public function scopeVisiveisPara(Builder $consulta, Usuario $usuario): void
    {
        $visiveis = $usuario->unidadesVisiveis();
        if ($visiveis === null) {
            return;
        }
        $consulta->where(fn (Builder $q) => $q->whereIn($this->qualifyColumn('unidade'), $visiveis)
            ->orWhere($this->qualifyColumn('solicitante_id'), $usuario->id));
    }

    /** Unidade da SS/OS: a da área executante; se a área for "ambos", a da máquina; se também, manutencao. */
    public static function unidadePara(?AreaExecutante $area, ?Maquina $maquina): string
    {
        if ($area && $area->unidade && $area->unidade !== 'ambos') {
            return $area->unidade;
        }
        if ($maquina && $maquina->unidade && $maquina->unidade !== 'ambos') {
            return $maquina->unidade;
        }

        return 'manutencao';
    }

    /** Aberta (aberta, em execução ou aguardando material)? Fechada = concluída, encerrada ou cancelada: só reabrir. */
    public function estaAberta(): bool
    {
        return in_array($this->status, self::STATUS_ABERTOS, true);
    }

    public function numeroFormatado(): string
    {
        return 'OS '.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
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

    /** Regrava total_minutos com a soma dos apontamentos fechados (normais + extras), agregada no banco. */
    public function recalcularTotalMinutos(): int
    {
        $total = (int) $this->apontamentos()->whereNotNull('fim')
            ->selectRaw('COALESCE(SUM(COALESCE(minutos_normais, 0) + COALESCE(minutos_extras, 0)), 0) AS total')
            ->value('total');
        $this->forceFill(['total_minutos' => $total])->saveQuietly();

        return $total;
    }

    public function solicitacao(): BelongsTo
    {
        return $this->belongsTo(SolicitacaoServico::class, 'solicitacao_id');
    }

    public function plano(): BelongsTo
    {
        return $this->belongsTo(PlanoPreventiva::class, 'plano_preventiva_id')->withoutGlobalScope('unidade');
    }

    public function maquina(): BelongsTo
    {
        return $this->belongsTo(Maquina::class, 'maquina_id')->withoutGlobalScope('unidade');
    }

    public function areaExecutante(): BelongsTo
    {
        return $this->belongsTo(AreaExecutante::class, 'area_executante_id')->withoutGlobalScope('unidade');
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'solicitante_id');
    }

    public function aprovadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'aprovado_por_id');
    }

    /** Mantenedores atribuídos à OS (vários; responsavel_id é o principal). */
    public function mantenedores(): BelongsToMany
    {
        return $this->belongsToMany(Mantenedor::class, 'os_ordem_mantenedores', 'ordem_servico_id', 'mantenedor_id')
            ->withoutGlobalScope('unidade');
    }

    /** Mantenedores da OS: os atribuídos e, se não estiver entre eles, o responsável principal. */
    public function mantenedoresAtribuidos(): Collection
    {
        $lista = $this->mantenedores;
        if ($this->responsavel_id && ! $lista->contains('id', $this->responsavel_id) && $this->responsavel) {
            $lista = $lista->prepend($this->responsavel);
        }

        return $lista->values();
    }

    /**
     * Atribui exatamente estes mantenedores (o primeiro vira o responsável principal, se o atual sair da lista).
     *
     * @return list<int> ids atribuídos
     */
    public function atribuirMantenedores(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids))));
        $atuais = $this->mantenedores()->pluck('os_mantenedores.id')->map(fn ($i) => (int) $i)->all();
        if ($remover = array_diff($atuais, $ids)) {
            $this->mantenedores()->detach($remover);
        }
        foreach (array_diff($ids, $atuais) as $id) {
            $this->mantenedores()->attach($id, ['criado_em' => now(), 'criado_por_id' => auth()->id()]);
        }
        if (! in_array((int) $this->responsavel_id, $ids, true)) {
            $this->forceFill(['responsavel_id' => $ids[0] ?? null])->save();
        }
        $this->unsetRelation('mantenedores');

        return $ids;
    }

    /** Acrescenta um mantenedor (ex.: quem começou a apontar horas), sem tirar os outros. */
    public function incluirMantenedor(int $id): void
    {
        if (! $this->mantenedores()->where('os_mantenedores.id', $id)->exists()) {
            $this->mantenedores()->attach($id, ['criado_em' => now(), 'criado_por_id' => auth()->id()]);
        }
        if ($this->responsavel_id === null) {
            $this->forceFill(['responsavel_id' => $id])->save();
        }
        $this->unsetRelation('mantenedores');
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(Mantenedor::class, 'responsavel_id')->withoutGlobalScope('unidade');
    }

    public function aceitePor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'aceite_por_id');
    }

    public function ordemProducao(): BelongsTo
    {
        return $this->belongsTo(OrdemProducao::class, 'ordem_producao_id');
    }

    public function apontamentoEstado(): BelongsTo
    {
        return $this->belongsTo(ApontamentoEstado::class, 'apontamento_estado_id');
    }

    public function apontamentos(): HasMany
    {
        return $this->hasMany(ApontamentoHoras::class, 'ordem_servico_id');
    }

    public function materiais(): HasMany
    {
        return $this->hasMany(MaterialOs::class, 'ordem_servico_id');
    }

    public function historico(): HasMany
    {
        return $this->hasMany(HistoricoOs::class, 'ordem_servico_id');
    }

    /** Avaliação do solicitante ao receber o serviço. */
    public const AVALIACOES = ['excelente' => 'Excelente', 'boa' => 'Boa', 'ruim' => 'Ruim'];

    public function interacoes(): HasMany
    {
        return $this->hasMany(InteracaoOs::class, 'ordem_servico_id');
    }

    /** Histórico completo: o da OS e o da SS que a originou (abertura, aprovação). */
    public function historicoCompleto(): Builder
    {
        return HistoricoOs::query()->where(fn ($q) => $q->where('ordem_servico_id', $this->id)
            ->when($this->solicitacao_id, fn ($q) => $q->orWhere(fn ($w) => $w->where('solicitacao_id', $this->solicitacao_id)->whereNull('ordem_servico_id'))));
    }

    public function checklist(): HasMany
    {
        return $this->hasMany(ChecklistOs::class, 'ordem_servico_id');
    }
}
