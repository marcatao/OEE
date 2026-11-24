<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\TemUnidade;
use Illuminate\Database\Eloquent\Model;

/**
 * Mão de obra (produto do TIPO MO da SB1) com o fator de serviço do portal (0 a 100%), usado na meta de produção:
 * horas por peça = (G1_QUANT do MO + fator%) × 100 ÷ 1,6667 (App\Services\MetaProducao).
 */
class MaoDeObra extends Model
{
    use Auditavel;
    use TemUnidade;

    protected $table = 'maos_de_obra';

    protected $fillable = ['b1_cod', 'fator_servico', 'unidade'];

    protected function casts(): array
    {
        return ['fator_servico' => 'float'];
    }

    /** Cria com fator 0 os MO da SB1 que ainda não estão na tabela (MO novos no Protheus). */
    public static function completar(): int
    {
        $existentes = static::withoutGlobalScopes()->pluck('b1_cod')->map(fn ($c) => rtrim($c))->flip();
        $novos = ProdutoProtheus::where('b1_tipo', 'MO')->where('d_e_l_e_t_', '<>', '*')->pluck('b1_cod')
            ->map(fn ($c) => rtrim($c))->unique()->reject(fn ($c) => isset($existentes[$c]))->values();
        foreach ($novos as $cod) {
            $m = new static(['b1_cod' => $cod, 'fator_servico' => 0, 'unidade' => 'manutencao']);
            $m->save();
        }

        return $novos->count();
    }

    /** Fator de serviço (%) do MO, lido sem escopo de unidade (a meta é a mesma para todos); sem cadastro = 0. */
    public static function fatorDe(string $codigo): float
    {
        return (float) (static::withoutGlobalScopes()->where('b1_cod', rtrim($codigo))->value('fator_servico') ?? 0);
    }
}
