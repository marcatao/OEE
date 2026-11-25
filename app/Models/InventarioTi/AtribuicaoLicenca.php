<?php

namespace App\Models\InventarioTi;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uso de uma licença de software: num computador do inventário e/ou por uma pessoa. Removida = removida_em preenchido. */
class AtribuicaoLicenca extends Model
{
    public $timestamps = false;

    protected $table = 'ti_licenca_atribuicoes';

    protected $fillable = ['licenca_id', 'item_id', 'usuario_id', 'atribuida_em', 'atribuida_por_id', 'removida_em', 'removida_por_id', 'observacao'];

    protected function casts(): array
    {
        return ['atribuida_em' => 'datetime', 'removida_em' => 'datetime'];
    }

    public function scopeAtivas(Builder $q): Builder
    {
        return $q->whereNull('removida_em');
    }

    public function licenca(): BelongsTo
    {
        return $this->belongsTo(ItemTi::class, 'licenca_id')->withoutGlobalScopes();
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemTi::class, 'item_id')->withoutGlobalScopes();
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function descricao(): string
    {
        return collect([$this->item?->identificacao(), $this->usuario?->nome])->filter()->implode(' · ') ?: '—';
    }
}
