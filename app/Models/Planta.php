<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Planta extends Model
{
    use Auditavel;

    protected $table = 'plantas';

    protected $fillable = ['codigo', 'nome', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function linhas(): HasMany
    {
        return $this->hasMany(Linha::class);
    }
}
