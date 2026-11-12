<?php

namespace App\Models\Manutencao;

use App\Models\Concerns\Auditavel;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Item da solicitação de material: produto, centro de custo, quantidade e a análise do administrador. */
class SolicitacaoMaterialItem extends Model
{
    use Auditavel;

    public const PRIORIDADES = ['alta' => 'Alta', 'media' => 'Média', 'baixa' => 'Baixa'];

    public const STATUS = ['pendente' => 'Aguardando análise', 'aprovado' => 'Aprovado', 'reprovado' => 'Reprovado'];

    protected $table = 'solicitacoes_material_itens';

    protected $fillable = ['solicitacao_material_id', 'produto_codigo', 'produto_descricao', 'um', 'centro_custo', 'quantidade_solicitada',
        'prioridade', 'observacao', 'status', 'quantidade_aprovada', 'motivo_reprovacao', 'aprovado_por_id', 'aprovado_em'];

    protected function casts(): array
    {
        return ['quantidade_solicitada' => 'decimal:4', 'quantidade_aprovada' => 'decimal:4', 'aprovado_em' => 'datetime'];
    }

    public function solicitacao(): BelongsTo
    {
        return $this->belongsTo(SolicitacaoMaterial::class, 'solicitacao_material_id');
    }

    public function aprovadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'aprovado_por_id');
    }

    public function rotuloStatus(): string
    {
        return self::STATUS[$this->status] ?? $this->status;
    }

    /** 2.5000 → "2,5". */
    public static function quantidade($valor): string
    {
        return $valor === null ? '—' : rtrim(rtrim(number_format((float) $valor, 4, ',', '.'), '0'), ',');
    }
}
