<?php

namespace App\Models\Manutencao;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\TemUnidade;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ferramenta da manutenção. Com mantenedor_id = está com aquele mantenedor; vazio = avulsa. */
class Ferramenta extends Model
{
    use Auditavel;
    use TemUnidade;

    protected $table = 'os_ferramentas';

    protected $fillable = ['unidade', 'codigo', 'descricao', 'marca_modelo', 'numero_serie', 'mantenedor_id', 'atribuida_em', 'observacao', 'ativo'];

    protected $attributes = ['ativo' => true, 'unidade' => 'manutencao'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'atribuida_em' => 'datetime'];
    }

    public function mantenedor(): BelongsTo
    {
        return $this->belongsTo(Mantenedor::class, 'mantenedor_id')->withoutGlobalScope('unidade');
    }

    /** Troca o dono (ou deixa avulsa); a data só muda quando o dono muda. */
    public function entregarPara(?int $mantenedorId): void
    {
        if ((int) $this->mantenedor_id !== (int) $mantenedorId) {
            $this->mantenedor_id = $mantenedorId;
            $this->atribuida_em = $mantenedorId ? now() : null;
        }
    }

    public function avulsa(): bool
    {
        return $this->mantenedor_id === null;
    }
}
