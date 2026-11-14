<?php

namespace App\Models\Assistente;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma pergunta feita ao "Pergunte para Alumbra": situação, etapas, consultas usadas e tokens gastos. */
class Pergunta extends Model
{
    protected $table = 'assistente_perguntas';

    public const CREATED_AT = 'criado_em';

    public const UPDATED_AT = null;

    protected $fillable = ['conversa_id', 'usuario_id', 'pergunta', 'posicao', 'situacao', 'etapas', 'ferramentas', 'modelo',
        'tokens_entrada', 'tokens_saida', 'tokens_cache_leitura', 'tokens_cache_escrita', 'erro', 'concluida_em'];

    protected $attributes = ['situacao' => 'pendente', 'etapas' => 0, 'tokens_entrada' => 0, 'tokens_saida' => 0,
        'tokens_cache_leitura' => 0, 'tokens_cache_escrita' => 0];

    protected function casts(): array
    {
        return ['concluida_em' => 'datetime', 'etapas' => 'integer'];
    }

    public function conversa(): BelongsTo
    {
        return $this->belongsTo(Conversa::class, 'conversa_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function emAndamento(): bool
    {
        return in_array($this->situacao, ['pendente', 'respondendo'], true);
    }
}
