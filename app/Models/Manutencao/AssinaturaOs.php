<?php

namespace App\Models\Manutencao;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Assinatura do solicitante na avaliação da OS feita no aplicativo (imagem PNG em base64). */
class AssinaturaOs extends Model
{
    const CREATED_AT = 'criado_em';

    const UPDATED_AT = null;

    protected $table = 'os_assinaturas';

    protected $fillable = ['ordem_servico_id', 'assinado_por_id', 'registrado_por_id', 'nome_assinante', 'avaliacao', 'imagem_png'];

    public function ordemServico(): BelongsTo
    {
        return $this->belongsTo(OrdemServico::class);
    }

    public function assinadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'assinado_por_id');
    }
}
