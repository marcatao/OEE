<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ordem de produção do Protheus (cópia da SC2 do banco Compras). Os campos c2_* só a sincronização grava; o portal
 * calcula o "status" (aberta, andamento, fechada, parada) — ver App\Services\StatusOrdemProducao.
 */
class OrdemProducao extends Model
{
    public $timestamps = false;

    public const STATUS = [
        'aberta' => 'Aberta',
        'andamento' => 'Andamento',
        'parada' => 'Parada',
        'fechada' => 'Fechada',
    ];

    protected $table = 'ordens_producao';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['c2_quant' => 'float', 'c2_quje' => 'float', 'c2_perda' => 'float', 'custo_meta' => 'float', 'r_e_c_n_o_' => 'integer',
            'status_em' => 'datetime', 'sincronizado_em' => 'datetime'];
    }

    public function apontamentos(): HasMany
    {
        return $this->hasMany(ApontamentoEstado::class, 'ordem_producao_id');
    }

    /** Produto da OP (SB1), pelo código; prefere o da mesma filial e, se não houver, o compartilhado. */
    public function produto(): ?ProdutoProtheus
    {
        return ProdutoProtheus::where('b1_cod', $this->c2_produto)
            ->whereIn('b1_filial', array_unique([$this->c2_filial, '']))
            ->orderByDesc('b1_filial')->first();
    }

    /** OPs que ainda podem receber apontamento (não fechadas nem excluídas no Protheus). */
    public function scopeApontaveis(Builder $q): Builder
    {
        return $q->where('status', '<>', 'fechada')->where('d_e_l_e_t_', '<>', '*');
    }

    /**
     * Busca de OP num campo só (TODAS as buscas de OP do portal, do app e da Timeline): cada palavra digitada tem de
     * aparecer no número da OP, no código (SKU) do produto ou na descrição do produto (SB1), em qualquer parte.
     * Ex.: "06323" acha pelo número; "PA0910" pelo SKU; "interruptor paralelo" pela descrição.
     */
    public function scopeBusca(Builder $q, ?string $termo): Builder
    {
        $palavras = array_slice(array_values(array_filter(preg_split('/\s+/', trim((string) $termo)) ?: [], fn ($p) => $p !== '')), 0, 5);
        foreach ($palavras as $palavra) {
            $like = '%'.str_replace(['\\', '%', '_', '['], ['\\\\', '\\%', '\\_', '\\['], $palavra).'%';
            $q->where(fn ($w) => $w->whereRaw("numero LIKE ? ESCAPE '\\'", [$like])
                ->orWhereRaw("c2_produto LIKE ? ESCAPE '\\'", [$like])
                ->orWhereIn('c2_produto', ProdutoProtheus::query()->select('b1_cod')->whereRaw("b1_desc LIKE ? ESCAPE '\\'", [$like])));
        }

        return $q;
    }

    /** Texto da OP nas listas com busca: "06267801001 · PA0910755 INTERRUPTOR PARALELO · Andamento". */
    public function textoBusca(?ProdutoProtheus $produto = null): string
    {
        $produto ??= $this->produto();

        return $this->numero.' · '.rtrim($this->c2_produto).($produto ? ' '.$produto->descricao() : '').' · '.$this->rotuloStatus();
    }

    public function rotuloStatus(): string
    {
        return self::STATUS[$this->status] ?? $this->status;
    }

    /** "20261105" → "05/11/2026". */
    public static function data(?string $aaaammdd): string
    {
        $d = trim((string) $aaaammdd);

        return strlen($d) === 8 ? substr($d, 6, 2).'/'.substr($d, 4, 2).'/'.substr($d, 0, 4) : '-';
    }
}
