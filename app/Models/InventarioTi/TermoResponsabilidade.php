<?php

namespace App\Models\InventarioTi;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Termo de responsabilidade de um item do inventário de TI. Nasce PENDENTE quando o item é entregue a um responsável;
 * ele assina no portal (pelo celular ou computador, com o dedo/mouse) ou na frente do administrador (presencial).
 * O conteúdo (dados do item na entrega) fica congelado em JSON; mudou o responsável, o pendente é cancelado.
 */
class TermoResponsabilidade extends Model
{
    const CREATED_AT = 'criado_em';

    const UPDATED_AT = null;

    public const STATUS = ['pendente' => 'Aguardando assinatura', 'assinado' => 'Assinado', 'cancelado' => 'Cancelado'];

    protected $table = 'ti_termos';

    protected $fillable = ['item_id', 'usuario_id', 'status', 'conteudo', 'gerado_por_id', 'assinado_em', 'nome_assinante', 'imagem_png',
        'registrado_por_id', 'presencial', 'assinado_ip', 'assinado_navegador', 'cancelado_em', 'motivo_cancelamento'];

    protected $hidden = ['imagem_png'];

    protected function casts(): array
    {
        return ['conteudo' => 'array', 'assinado_em' => 'datetime', 'cancelado_em' => 'datetime', 'presencial' => 'boolean'];
    }

    public function scopePendentes(Builder $q): Builder
    {
        return $q->where('status', 'pendente');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemTi::class, 'item_id')->withoutGlobalScopes();
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function geradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'gerado_por_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por_id');
    }

    public function numeroFormatado(): string
    {
        return 'TR-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }

    public function rotuloStatus(): string
    {
        return self::STATUS[$this->status] ?? $this->status;
    }
}
