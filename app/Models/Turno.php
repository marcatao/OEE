<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;

class Turno extends Model
{
    use Auditavel;

    protected $table = 'turnos';

    protected $fillable = ['nome', 'inicio', 'fim', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }
}
