<?php

namespace App\Models\Manutencao;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/** Histórico da SS/OS (troca de status e alterações): só é inserido, nunca alterado. */
class HistoricoOs extends Model
{
    public const CREATED_AT = 'criado_em';

    public const UPDATED_AT = null;

    protected $table = 'os_historico';

    protected $fillable = ['ordem_servico_id', 'solicitacao_id', 'acao', 'status_anterior', 'status_novo', 'observacao', 'usuario_id'];

    protected static function booted(): void
    {
        static::creating(function (HistoricoOs $h) {
            $h->usuario_id ??= Auth::id();
        });
    }

    protected function casts(): array
    {
        return ['criado_em' => 'datetime'];
    }

    public function ordemServico(): BelongsTo
    {
        return $this->belongsTo(OrdemServico::class, 'ordem_servico_id');
    }

    public function solicitacao(): BelongsTo
    {
        return $this->belongsTo(SolicitacaoServico::class, 'solicitacao_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    /** Nome legível de cada ação. */
    public const ACOES = [
        'aberta' => 'SS aberta', 'alterada' => 'Dados alterados', 'aprovada' => 'SS aprovada — OS emitida', 'rejeitada' => 'SS rejeitada',
        'cancelada' => 'SS cancelada', 'gerada_preventiva' => 'OS preventiva gerada', 'distribuida' => 'Mantenedores definidos',
        'iniciar' => 'Início do atendimento', 'aguardar_material' => 'Aguardando material', 'retomar' => 'Atendimento retomado',
        'concluir' => 'Serviço concluído', 'reabrir' => 'OS reaberta', 'cancelar' => 'OS cancelada',
        'avaliada' => 'Avaliada pelo solicitante', 'avaliada_ruim' => 'Avaliada como Ruim — OS reaberta',
        'aceite' => 'Aceite do solicitante', 'servico_recusado' => 'Serviço recusado pelo solicitante',
        'maquina_parada' => 'Máquina parada / liberada', 'horas_lancadas' => 'Horas lançadas', 'horas_excluidas' => 'Horas excluídas',
        'material_requisitado' => 'Material requisitado', 'assinatura' => 'Assinatura no aplicativo',
        'nao_aceita' => 'SS não aceita pelo mantenedor (aplicativo)',
    ];

    /** Ações que marcam as etapas da SS/OS (destacadas). */
    public const MARCOS = ['aberta', 'aprovada', 'iniciar', 'concluir', 'avaliada', 'avaliada_ruim', 'gerada_preventiva'];

    public function rotuloAcao(): string
    {
        return self::ACOES[$this->acao] ?? (str_starts_with($this->acao, 'material_')
            ? 'Material: '.str_replace('_', ' ', substr($this->acao, 9)) : ucfirst(str_replace('_', ' ', $this->acao)));
    }

    /** "aberta → em execucao" quando a situação mudou; senão "". */
    public function mudancaStatus(): string
    {
        if (! $this->status_novo || $this->status_novo === $this->status_anterior) {
            return '';
        }

        return ($this->status_anterior ? str_replace('_', ' ', $this->status_anterior).' → ' : '').str_replace('_', ' ', $this->status_novo);
    }

    public function ehMarco(): bool
    {
        return in_array($this->acao, self::MARCOS, true);
    }
}
