<?php

namespace App\Models\Faturamento;

use App\Services\Faturamento\EtapasPedidoVenda;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Pedido de venda (filial + número) com a etapa, desde quando, valores e datas, calculados pelo portal
 * (App\Services\Faturamento\EtapasPedidoVenda) a partir de pedidos_venda (SC5 + SC6, um item por linha) e
 * pedidos_venda_liberacoes (SC9). Só o recálculo das etapas grava aqui.
 */
class PedidoVendaEtapa extends Model
{
    public $timestamps = false;

    protected $table = 'pedidos_venda_etapas';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['valor_total' => 'float', 'valor_saldo' => 'float', 'valor_faturado' => 'float', 'parcial' => 'boolean',
            'etapa_desde' => 'datetime', 'calculado_em' => 'datetime'];
    }

    public function numero(): string
    {
        return rtrim((string) $this->c5_num);
    }

    public function rotuloEtapa(): string
    {
        return EtapasPedidoVenda::ETAPAS[$this->etapa]['rotulo'] ?? 'Encerrado';
    }

    public function emAberto(): bool
    {
        return $this->etapa !== null && $this->etapa !== EtapasPedidoVenda::FATURADO;
    }

    /** Valor mostrado na tela: o saldo a faturar (em aberto) ou o faturado. */
    public function valor(): float
    {
        return $this->emAberto() ? (float) $this->valor_saldo : (float) $this->valor_faturado;
    }

    /** Dias inteiros desde que o pedido entrou na etapa atual. */
    public function diasNaEtapa(?CarbonImmutable $agora = null): ?int
    {
        if (! $this->etapa_desde) {
            return null;
        }

        return max(0, (int) floor($this->etapa_desde->diffInMinutes($agora ?? CarbonImmutable::now(), true) / 1440));
    }

    /** "AAAAMMDD" → "dd/mm/aaaa" (vazio = "-"). */
    public static function data(?string $valor): string
    {
        $v = trim((string) $valor);

        return preg_match('/^(\d{4})(\d{2})(\d{2})$/', $v, $m) ? "{$m[3]}/{$m[2]}/{$m[1]}" : '-';
    }
}
