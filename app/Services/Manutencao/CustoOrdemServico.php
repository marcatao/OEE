<?php

namespace App\Services\Manutencao;

use App\Models\Compras\PedidoCompra;
use App\Models\Manutencao\OrdemServico;
use Illuminate\Support\Collection;

/**
 * Quanto a OS está custando: pedidos de compra (valor do Protheus; reprovados, cancelados e com erro não contam) +
 * materiais do almoxarifado com custo unitário informado (cancelados não contam). As horas apontadas aparecem à parte
 * (ainda não há custo/hora da mão de obra).
 */
final class CustoOrdemServico
{
    /**
     * @param  Collection<int, PedidoCompra>  $pedidos  pedidos da OS (já lidos da muro)
     * @return array{pedidos: float, pedidos_qtd: int, pedidos_sem_valor: int, materiais: float, materiais_sem_custo: int, total: float, minutos: int}
     */
    public static function resumo(OrdemServico $os, Collection $pedidos, Collection $materiais): array
    {
        $contam = $pedidos->reject(fn ($p) => in_array($p->status, PedidoCompra::SEM_CUSTO, true));
        $valorPedidos = round($contam->sum(fn ($p) => $p->valorCusto()), 2);
        $mats = $materiais->where('status', '<>', 'cancelado');
        $valorMateriais = round($mats->sum(fn ($m) => (float) $m->quantidade * (float) $m->custo_unitario), 2);

        return [
            'pedidos' => $valorPedidos,
            'pedidos_qtd' => $contam->count(),
            'pedidos_sem_valor' => $contam->filter(fn ($p) => $p->valor_total === null)->count(),
            'materiais' => $valorMateriais,
            'materiais_sem_custo' => $mats->filter(fn ($m) => $m->custo_unitario === null)->count(),
            'total' => round($valorPedidos + $valorMateriais, 2),
            'minutos' => (int) $os->total_minutos,
        ];
    }

    public static function reais(float $valor): string
    {
        return 'R$ '.number_format($valor, 2, ',', '.');
    }
}
