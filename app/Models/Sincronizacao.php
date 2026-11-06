<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma execução da sincronização com o Protheus. */
class Sincronizacao extends Model
{
    public $timestamps = false;

    protected $table = 'sincronizacoes';

    protected $fillable = ['chave', 'origem', 'disparo', 'usuario_id', 'iniciado_em', 'terminado_em', 'situacao',
        'lidos', 'novos', 'atualizados', 'ignorados', 'excluidos', 'mensagem'];

    protected function casts(): array
    {
        return ['iniciado_em' => 'datetime', 'terminado_em' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    public function duracaoSegundos(): ?int
    {
        return $this->terminado_em ? (int) $this->iniciado_em->diffInSeconds($this->terminado_em) : null;
    }
}
