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

    /** Prioridades antigas (antes dos códigos da empresa): só para exibir os itens já gravados. */
    public const PRIORIDADES_ANTIGAS = ['alta' => 'Alta (antiga)', 'media' => 'Média (antiga)', 'baixa' => 'Baixa (antiga)'];

    public const PRIORIDADE_PADRAO = 'NO';

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

    /** Prioridades atuais: código => descrição (config oee.prioridades_material). @return array<string, string> */
    public static function prioridades(): array
    {
        return array_map(fn ($p) => $p['descricao'], config('oee.prioridades_material', []));
    }

    /** "MP — Máquina parada" (ou a antiga, para itens gravados antes dos códigos). */
    public function rotuloPrioridade(): string
    {
        $atuais = self::prioridades();

        return isset($atuais[$this->prioridade]) ? $this->prioridade.' — '.$atuais[$this->prioridade]
            : (self::PRIORIDADES_ANTIGAS[$this->prioridade] ?? (string) $this->prioridade);
    }

    /** critica | alta | normal (cor na tela). */
    public function nivelPrioridade(): string
    {
        return config('oee.prioridades_material.'.$this->prioridade.'.nivel')
            ?? ['alta' => 'alta', 'media' => 'normal', 'baixa' => 'normal'][$this->prioridade] ?? 'normal';
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
