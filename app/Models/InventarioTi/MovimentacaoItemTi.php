<?php

namespace App\Models\InventarioTi;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Histórico do item: cada mudança de responsável, local, centro de custo, situação, vínculo ou licença vira uma linha. */
class MovimentacaoItemTi extends Model
{
    const CREATED_AT = 'criado_em';

    const UPDATED_AT = null;

    public const CAMPOS = [
        'cadastro' => 'Cadastro',
        'responsavel' => 'Responsável',
        'local' => 'Local',
        'centro_custo' => 'Centro de custo',
        'situacao' => 'Situação',
        'vinculado' => 'Aparelho do chip',
        'licenca' => 'Licença',
        'termo' => 'Termo de responsabilidade',
    ];

    protected $table = 'ti_movimentacoes';

    protected $fillable = ['item_id', 'campo', 'valor_anterior', 'valor_novo', 'observacao', 'usuario_id'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemTi::class, 'item_id')->withoutGlobalScopes();
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function rotuloCampo(): string
    {
        return self::CAMPOS[$this->campo] ?? $this->campo;
    }
}
