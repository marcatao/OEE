<?php

namespace App\Models\Assistente;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conversa do "Pergunte para Alumbra". "mensagens" guarda o histórico no formato da API da Claude (blocos de texto,
 * pedidos de consulta e resultados), para a conversa continuar nas próximas perguntas.
 */
class Conversa extends Model
{
    protected $table = 'assistente_conversas';

    public const CREATED_AT = 'criado_em';

    public const UPDATED_AT = 'atualizado_em';

    protected $fillable = ['usuario_id', 'titulo', 'mensagens'];

    protected function casts(): array
    {
        return ['mensagens' => 'array'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function perguntas(): HasMany
    {
        return $this->hasMany(Pergunta::class, 'conversa_id');
    }

    /** @return list<array<string, mixed>> */
    public function historico(): array
    {
        return $this->mensagens ?? [];
    }
}
