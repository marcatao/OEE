<?php

namespace App\Models\Manutencao;

use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;

/** Justificativa das horas "incomuns" de um setor num mês (competência = 1º dia do mês), para a diretoria. */
class JustificativaSetor extends Model
{
    use Auditavel;

    protected $table = 'os_justificativas_setor';

    protected $fillable = ['unidade', 'centro_custo', 'competencia', 'texto'];

    protected function casts(): array
    {
        return ['competencia' => 'date'];
    }
}
