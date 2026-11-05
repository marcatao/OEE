<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;

class MotivoParada extends Model
{
    use Auditavel;

    protected $table = 'motivos_parada';

    protected $fillable = ['codigo', 'descricao', 'categoria', 'planejada', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'planejada' => 'boolean'];
    }
}
