<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Filtro salvo por um usuário numa tela de relatório (ex.: Timeline). "filtros" guarda os valores em JSON;
 * "padrao" marca o filtro aplicado ao abrir a tela.
 */
class FiltroUsuario extends Model
{
    const CREATED_AT = 'criado_em';

    const UPDATED_AT = 'atualizado_em';

    protected $table = 'filtros_usuarios';

    protected $fillable = ['usuario_id', 'tela', 'nome', 'filtros', 'padrao'];

    protected function casts(): array
    {
        return ['filtros' => 'array', 'padrao' => 'boolean'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    public function scopeDaTela(Builder $q, Usuario $usuario, string $tela): Builder
    {
        return $q->where('usuario_id', $usuario->id)->where('tela', $tela);
    }
}
