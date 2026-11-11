<?php

namespace App\Models\Manutencao;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\TemUnidade;
use Illuminate\Database\Eloquent\Model;

/** Banco de sugestões para a descrição da SS. */
class SugestaoDescricao extends Model
{
    use Auditavel;
    use TemUnidade;

    protected $table = 'os_sugestoes_descricao';

    protected $fillable = ['unidade', 'texto', 'ativo'];

    protected $attributes = ['unidade' => 'manutencao', 'ativo' => true];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }
}
