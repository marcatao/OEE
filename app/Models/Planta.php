<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\TemUnidade;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Planta extends Model
{
    use Auditavel;
    use TemUnidade;

    protected $table = 'plantas';

    protected $fillable = ['unidade', 'codigo', 'nome', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function linhas(): HasMany
    {
        return $this->hasMany(Linha::class);
    }
}
