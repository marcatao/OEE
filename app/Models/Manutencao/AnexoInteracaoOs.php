<?php

namespace App\Models\Manutencao;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Arquivo anexado a um comentário da OS (guardado no disco privado "local"). */
class AnexoInteracaoOs extends Model
{
    public const CREATED_AT = 'criado_em';

    public const UPDATED_AT = null;

    protected $table = 'os_interacao_anexos';

    protected $fillable = ['interacao_id', 'nome', 'caminho', 'mime', 'tamanho'];

    public function interacao(): BelongsTo
    {
        return $this->belongsTo(InteracaoOs::class, 'interacao_id');
    }

    public function ehImagem(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    /** 1536000 → "1,5 MB". */
    public function tamanhoLegivel(): string
    {
        $b = (int) $this->tamanho;

        return $b >= 1048576 ? number_format($b / 1048576, 1, ',', '.').' MB' : max(1, (int) round($b / 1024)).' KB';
    }
}
