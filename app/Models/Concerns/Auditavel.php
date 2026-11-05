<?php

namespace App\Models\Concerns;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Datas em português (criado_em/atualizado_em) e quem criou/alterou o registro.
 */
trait Auditavel
{
    public static function bootAuditavel(): void
    {
        static::creating(function ($modelo) {
            $modelo->criado_por_id ??= Auth::id();
        });
        static::updating(function ($modelo) {
            $modelo->atualizado_por_id = Auth::id();
        });
    }

    public function getCreatedAtColumn(): string
    {
        return 'criado_em';
    }

    public function getUpdatedAtColumn(): string
    {
        return 'atualizado_em';
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'criado_por_id');
    }

    public function atualizadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'atualizado_por_id');
    }
}
