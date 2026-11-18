{{-- OS fechada: só o que foi comprado para ela, produto a produto. O valor aparece quando o pedido (ou a SC, pelos
     pedidos gerados) foi concluído no Protheus. Variáveis: $pedidos (com itens), $muroOk. --}}
@use('App\Services\Manutencao\CustoOrdemServico')
@use('App\Models\Manutencao\SolicitacaoMaterialItem')
@php($totalConcluido = 0.0)
@if (! $muroOk)
  <p class="aviso erro" role="alert">Não foi possível consultar a integração com o Protheus agora: a situação pode estar desatualizada.</p>
@endif
<div class="rolagem">
<table class="tabela pc-resumo">
  <thead><tr><th>Documento</th><th>Situação</th><th>Produto</th><th class="num">Quantidade</th><th class="num">Valor</th></tr></thead>
  <tbody>
  @foreach ($pedidos as $pc)
    @foreach ($pc->itens->sortBy('item')->values() as $n => $it)
      @php($concluido = $pc->status === 'concluido')
      {{-- Pedido concluído: o que foi entregue (quantidade recebida × preço); SC atendida: o valor do pedido gerado. --}}
      @php($valor = $concluido ? ($pc->ehSolicitacao() ? (float) $it->valor_total
        : ($it->valor_unitario !== null && $it->quantidade_entregue !== null ? round((float) $it->valor_unitario * (float) $it->quantidade_entregue, 2) : (float) $it->valor_total)) : null)
      @php($totalConcluido += (float) $valor)
      <tr @class(['pc-resumo-inicio' => $n === 0])>
        <td>@if ($n === 0)<strong>{{ $pc->numeroFormatado() }}</strong> <small class="suave">{{ $pc->rotuloTipo() }}</small>
          @if ($pc->numero_pedido)<br><span class="etiqueta pc-num-protheus">{{ $pc->rotuloNumeroProtheus() }}</span>@endif @endif</td>
        <td>@if ($n === 0)<span class="etiqueta pc-{{ $pc->status }}">{{ $pc->rotuloStatus() }}</span>
          @if ($pc->concluido_em)<br><small class="suave">@dataHora($pc->concluido_em)</small>@endif @endif</td>
        <td><code>{{ $it->produto_codigo }}</code> {{ $it->produto_descricao }}@if ($pc->ehSolicitacao() && $it->pedido_gerado)<br><small class="suave">pedido {{ $it->pedido_gerado }}</small>@endif</td>
        <td class="num">{{ SolicitacaoMaterialItem::quantidade($concluido && ! $pc->ehSolicitacao() && $it->quantidade_entregue !== null ? $it->quantidade_entregue : $it->quantidade) }} {{ $it->um }}</td>
        <td class="num">@if ($valor !== null)<strong>{{ CustoOrdemServico::reais($valor) }}</strong>@else<small class="suave">quando concluir</small>@endif</td>
      </tr>
    @endforeach
  @endforeach
  </tbody>
  <tfoot><tr><th colspan="4">Total dos concluídos</th><th class="num">{{ CustoOrdemServico::reais($totalConcluido) }}</th></tr></tfoot>
</table>
</div>
