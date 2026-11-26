<?php

namespace App\Services\Faturamento;

use App\Models\Faturamento\PedidoVendaEtapa;
use App\Models\PedidoVenda;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Faturamento › Pedidos de venda: cartões com a quantidade e o valor dos pedidos em cada etapa e a lista dos pedidos,
 * sobre pedidos_venda_etapas (etapa calculada por EtapasPedidoVenda a partir das cópias do Protheus). Os pedidos de venda não têm
 * unidade: quem tem a permissão vê todos.
 * Mês do filtro: faturados = faturamento no mês; em aberto = conforme FiltroPedidosVenda::ABERTOS. Valor: em aberto =
 * saldo a faturar; faturados = valor faturado.
 */
class PedidosVendaDashboard
{
    public const POR_PAGINA = 50;

    /** @return array{pedidos: LengthAwarePaginator, cartoes: array<string, array{quantidade: int, valor: float}>, totais: array} */
    public function montar(FiltroPedidosVenda $filtro, int $pagina = 1, ?string $caminho = null): array
    {
        $cartoes = array_fill_keys(array_keys(EtapasPedidoVenda::ETAPAS), ['quantidade' => 0, 'valor' => 0.0]);
        $linhas = $this->consulta($filtro)
            ->selectRaw('etapa, COUNT(*) AS quantidade, SUM(valor_saldo) AS saldo, SUM(valor_faturado) AS faturado')
            ->groupBy('etapa')->toBase()->get();
        foreach ($linhas as $l) {
            if (isset($cartoes[$l->etapa])) {
                $cartoes[$l->etapa] = ['quantidade' => (int) $l->quantidade,
                    'valor' => (float) ($l->etapa === EtapasPedidoVenda::FATURADO ? $l->faturado : $l->saldo)];
            }
        }
        $abertas = array_diff_key($cartoes, [EtapasPedidoVenda::FATURADO => true]);

        $pedidos = $this->consulta($filtro)
            ->when($filtro->etapas, fn ($q) => $q->whereIn('etapa', $filtro->etapas))
            ->orderByRaw($this->ordemEtapas())
            ->orderByRaw("CASE WHEN entrega_prevista = '' THEN 1 ELSE 0 END")->orderBy('entrega_prevista')
            ->orderByDesc('faturado_em')->orderBy('c5_num')
            ->paginate(self::POR_PAGINA, ['*'], 'page', $pagina)
            ->withPath($caminho ?? request()->url())->appends($filtro->parametros());

        return [
            'pedidos' => $pedidos,
            'cartoes' => $cartoes,
            'totais' => [
                'abertos' => array_sum(array_column($abertas, 'quantidade')),
                'valor_aberto' => array_sum(array_column($abertas, 'valor')),
            ],
        ];
    }

    /** Pedidos do mês e da busca (todas as etapas). */
    public function consulta(FiltroPedidosVenda $filtro): Builder
    {
        [$inicio, $fim] = $filtro->dias();
        $busca = $filtro->busca;

        return PedidoVendaEtapa::query()->whereNotNull('etapa')
            ->where(fn ($q) => $q
                ->where(fn ($f) => $f->where('etapa', EtapasPedidoVenda::FATURADO)->whereBetween('faturado_em', [$inicio, $fim]))
                ->orWhere(fn ($a) => $a->where('etapa', '<>', EtapasPedidoVenda::FATURADO)
                    ->when($filtro->abertos === 'mes', fn ($w) => $w->whereBetween('entrega_prevista', [$inicio, $fim]))
                    ->when($filtro->abertos === 'mes_atrasados', fn ($w) => $w->where('entrega_prevista', '<=', $fim))))
            ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w->where('c5_num', 'like', "%{$busca}%")
                ->orWhere('c5_cliente', 'like', "%{$busca}%")
                ->orWhere('cliente_nome', 'like', "%{$busca}%")
                ->orWhereIn('c5_num', PedidoVenda::where(fn ($p) => $p->where('c6_produto', 'like', "%{$busca}%")
                    ->orWhere('c6_descri', 'like', "%{$busca}%")->orWhere('c6_pedcli', 'like', "%{$busca}%"))->select('c5_num'))));
    }

    /** ORDER BY na ordem do processo (texto fixo das constantes, sem dados do usuário). */
    private function ordemEtapas(): string
    {
        $casos = '';
        foreach (array_keys(EtapasPedidoVenda::ETAPAS) as $i => $etapa) {
            $casos .= " WHEN '{$etapa}' THEN {$i}";
        }

        return "CASE etapa{$casos} ELSE 99 END";
    }
}
