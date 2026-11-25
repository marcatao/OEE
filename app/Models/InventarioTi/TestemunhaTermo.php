<?php

namespace App\Models\InventarioTi;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Testemunha (1 ou 2) do contrato de comodato: um usuário do portal que assina no portal, como o comodatário. */
class TestemunhaTermo extends Model
{
    const CREATED_AT = 'criado_em';

    const UPDATED_AT = null;

    protected $table = 'ti_termo_testemunhas';

    protected $fillable = ['termo_id', 'ordem', 'usuario_id', 'nome', 'rg', 'imagem_png', 'assinado_em', 'registrado_por_id', 'presencial',
        'assinado_ip', 'definido_por_id'];

    protected $hidden = ['imagem_png'];

    protected function casts(): array
    {
        return ['ordem' => 'integer', 'assinado_em' => 'datetime', 'presencial' => 'boolean'];
    }

    public function termo(): BelongsTo
    {
        return $this->belongsTo(TermoResponsabilidade::class, 'termo_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por_id');
    }

    public function assinou(): bool
    {
        return $this->assinado_em !== null;
    }
}
