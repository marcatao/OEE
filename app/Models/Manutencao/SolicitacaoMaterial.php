<?php

namespace App\Models\Manutencao;

use App\Models\Concerns\Auditavel;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Solicitação de material do mantenedor à administração da área (cabeçalho; os itens são analisados um a um). */
class SolicitacaoMaterial extends Model
{
    use Auditavel;

    public const STATUS = [
        'pendente' => 'Aguardando análise',
        'em_analise' => 'Em análise',
        'analisada' => 'Analisada',
        'cancelada' => 'Cancelada',
    ];

    protected $table = 'solicitacoes_material';

    protected $fillable = ['unidade', 'solicitante_id', 'status', 'observacao'];

    protected function casts(): array
    {
        return ['criado_em' => 'datetime'];
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'solicitante_id');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(SolicitacaoMaterialItem::class, 'solicitacao_material_id');
    }

    public function numeroFormatado(): string
    {
        return 'SM '.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function rotuloStatus(): string
    {
        return self::STATUS[$this->status] ?? $this->status;
    }

    /** Recalcula a situação pelos itens: nenhum analisado = pendente; alguns = em análise; todos = analisada. */
    public function recalcularStatus(): void
    {
        if ($this->status === 'cancelada') {
            return;
        }
        $total = $this->itens()->count();
        $pendentes = $this->itens()->where('status', 'pendente')->count();
        $this->forceFill(['status' => $pendentes === $total ? 'pendente' : ($pendentes === 0 ? 'analisada' : 'em_analise')])->save();
    }
}
