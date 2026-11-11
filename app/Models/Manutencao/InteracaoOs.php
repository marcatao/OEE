<?php

namespace App\Models\Manutencao;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Comentário na conversa da OS (com anexos). Só é inserido. */
class InteracaoOs extends Model
{
    public const CREATED_AT = 'criado_em';

    public const UPDATED_AT = null;

    protected $table = 'os_interacoes';

    protected $fillable = ['ordem_servico_id', 'usuario_id', 'texto'];

    protected function casts(): array
    {
        return ['criado_em' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function ordemServico(): BelongsTo
    {
        return $this->belongsTo(OrdemServico::class, 'ordem_servico_id');
    }

    public function anexos(): HasMany
    {
        return $this->hasMany(AnexoInteracaoOs::class, 'interacao_id');
    }

    /** Texto com os links clicáveis (o resto escapado). */
    public function textoHtml(): string
    {
        $seguro = e((string) $this->texto);

        return nl2br(preg_replace_callback('~\b(https?://[^\s<]+|www\.[^\s<]+)~i', function ($m) {
            $url = html_entity_decode($m[1]);
            $href = str_starts_with(strtolower($url), 'www.') ? 'https://'.$url : $url;

            return '<a href="'.e($href).'" target="_blank" rel="noopener noreferrer">'.e($url).'</a>';
        }, $seguro));
    }
}
