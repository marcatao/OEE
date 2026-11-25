<?php

namespace App\Models\InventarioTi;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\TemUnidade;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Descrição padrão dos itens do inventário de TI (para todos escreverem igual). Com tipo: só aparece para itens daquele
 * tipo; sem tipo: para qualquer um. O item guarda uma cópia do texto: mudar o texto aqui não muda os itens já gravados.
 */
class DescricaoItemTi extends Model
{
    use Auditavel;
    use TemUnidade;

    protected $table = 'ti_descricoes';

    protected $fillable = ['unidade', 'tipo_id', 'descricao', 'ativo'];

    protected $attributes = ['unidade' => 'tecnologia', 'ativo' => true];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoItemTi::class, 'tipo_id')->withoutGlobalScopes();
    }

    public function itens(): HasMany
    {
        return $this->hasMany(ItemTi::class, 'descricao_id');
    }
}
