<?php

namespace App\Models\InventarioTi;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Atributo livre de um item do inventário de TI (nome + valor, ex.: IP = 10.0.5.20, Memória = 16 GB). */
class AtributoItemTi extends Model
{
    const CREATED_AT = 'criado_em';

    const UPDATED_AT = 'atualizado_em';

    protected $table = 'ti_item_atributos';

    protected $fillable = ['item_id', 'nome', 'valor', 'ordem'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemTi::class, 'item_id')->withoutGlobalScopes();
    }
}
