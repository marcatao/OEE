<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\TemUnidade;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Versão do aplicativo Android publicada no portal (o app se atualiza por ela). Sempre "ambos" (todas as unidades). */
class VersaoAplicativo extends Model
{
    use Auditavel;
    use TemUnidade;

    protected $table = 'versoes_aplicativo';

    protected $fillable = ['codigo', 'nome', 'arquivo', 'tamanho', 'sha256', 'obrigatoria', 'ativo', 'observacao', 'unidade'];

    protected function casts(): array
    {
        return ['codigo' => 'integer', 'tamanho' => 'integer', 'obrigatoria' => 'boolean', 'ativo' => 'boolean'];
    }

    public function scopeAtivas(Builder $q): Builder
    {
        return $q->where('ativo', true);
    }

    public function nomeArquivo(): string
    {
        return 'oee-alumbra-'.$this->nome.'-'.$this->codigo.'.apk';
    }
}
