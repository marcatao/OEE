<?php

namespace App\Models\Aplicativo;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Token de um celular com o aplicativo OEE Alumbra (só o hash fica no banco). */
class TokenAplicativo extends Model
{
    const CREATED_AT = 'criado_em';

    const UPDATED_AT = null;

    protected $table = 'tokens_aplicativo';

    protected $fillable = ['usuario_id', 'token_hash', 'dispositivo', 'versao_app', 'ultimo_uso_em', 'revogado_em'];

    protected function casts(): array
    {
        return ['criado_em' => 'datetime', 'ultimo_uso_em' => 'datetime', 'revogado_em' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}
