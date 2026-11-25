<?php

namespace App\Models\InventarioTi;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\TemUnidade;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Tipo de item do inventário de TI (Notebook, Monitor, Licença de software…), dentro de uma categoria fixa. */
class TipoItemTi extends Model
{
    use Auditavel;
    use TemUnidade;

    protected $table = 'ti_tipos';

    protected $fillable = ['unidade', 'categoria', 'nome', 'ativo'];

    protected $attributes = ['unidade' => 'tecnologia', 'ativo' => true];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function itens(): HasMany
    {
        return $this->hasMany(ItemTi::class, 'tipo_id');
    }

    public function rotuloCategoria(): string
    {
        return ItemTi::CATEGORIAS[$this->categoria] ?? (string) $this->categoria;
    }
}
