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
}
