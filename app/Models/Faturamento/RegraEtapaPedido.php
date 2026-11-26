<?php

namespace App\Models\Faturamento;

use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Regra que coloca uma liberação de pedido (SC9) numa etapa do WMS/expedição (Faturamento › Etapas dos pedidos):
 * "campo [operador] valores", sobre um campo da SC9 (a liberação). Várias regras da mesma etapa =
 * basta UMA valer. Configuração da empresa toda (os pedidos de venda não têm unidade): não usa TemUnidade.
 * Desativada, não excluída.
 */
class RegraEtapaPedido extends Model
{
    use Auditavel;

    protected $table = 'regras_etapa_pedido';

    protected $fillable = ['etapa', 'campo', 'operador', 'valores', 'observacao', 'ativo'];

    public const OPERADORES = [
        'igual' => 'é um destes valores',
        'diferente' => 'não é nenhum destes valores',
        'comeca' => 'começa com um destes valores',
        'preenchido' => 'está preenchido',
        'vazio' => 'está vazio',
    ];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function scopeAtivas(Builder $q): Builder
    {
        return $q->where('ativo', true);
    }

    /** @return list<string> valores da lista, sem espaços (maiúsculas não importam na comparação) */
    public function listaValores(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->valores)), fn ($v) => $v !== ''));
    }

    public function descricao(): string
    {
        $valores = in_array($this->operador, ['preenchido', 'vazio'], true) ? '' : ' '.implode(', ', $this->listaValores());

        return $this->campo.' '.(self::OPERADORES[$this->operador] ?? $this->operador).$valores;
    }
}
