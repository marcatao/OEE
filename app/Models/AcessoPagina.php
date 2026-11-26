<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Página do portal aberta por um usuário logado (Segurança › Acesso às páginas). Só o Administrador de Tecnologia vê. */
class AcessoPagina extends Model
{
    public $timestamps = false;

    protected $table = 'acessos_paginas';

    protected $fillable = ['usuario_id', 'data_hora', 'rota', 'caminho', 'ip'];

    protected function casts(): array
    {
        return ['data_hora' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}
