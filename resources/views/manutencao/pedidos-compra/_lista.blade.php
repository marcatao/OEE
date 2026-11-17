{{-- Pedidos e solicitações de compra (tabela muro do Protheus). Variáveis: $pedidos (com itens), $podeAdministrar (admin da unidade),
     $muroOk (false = não deu para consultar o Protheus agora). --}}
@use('App\Services\Manutencao\CustoOrdemServico')
@use('App\Models\Manutencao\SolicitacaoMaterialItem')
@if (! $muroOk)
  <p class="aviso erro" role="alert">Não foi possível consultar a integração com o Protheus agora: a situação dos pedidos pode estar desatualizada.</p>
@endif
@forelse ($pedidos as $pc)
  <article class="pc-pedido" id="pc-{{ $pc->id }}">
    <header class="pc-cabeca">
      <div>
        <strong>{{ $pc->numeroFormatado() }}</strong>
        <span class="suave">{{ $pc->rotuloTipo() }}</span>
        <span class="etiqueta pc-{{ $pc->status }}">{{ $pc->rotuloStatus() }}</span>
        @if ($pc->numero_pedido)<span class="etiqueta pc-num-protheus" title="Número no Protheus">{{ $pc->rotuloNumeroProtheus() }}</span>@endif
      </div>
      <div class="pc-valor">
        @if ($pc->valor_total !== null)<strong>{{ CustoOrdemServico::reais((float) $pc->valor_total) }}</strong>@else<span class="suave">valor: {{ $pc->ehSolicitacao() ? 'quando virar pedido' : 'aguardando Protheus' }}</span>@endif
      </div>
    </header>
    <dl class="pc-dados">
      <div><dt>CC origem → destino</dt><dd>{{ $pc->centro_custo_origem }} → {{ $pc->centro_custo_destino }}</dd></div>
      <div><dt>Gerado por</dt><dd>{{ $pc->criadoPor?->nome }} · @dataHora($pc->criado_em)</dd></div>
      @if ($pc->integrado_em)<div><dt>Integrado no Protheus</dt><dd>@dataHora($pc->integrado_em)</dd></div>@endif
      @if ($pc->aprovador || $pc->aprovado_em)<div><dt>Aprovado por</dt><dd>{{ $pc->aprovador ?? '—' }}@if ($pc->aprovado_em) · @dataHora($pc->aprovado_em)@endif</dd></div>@endif
      @if ($pc->concluido_em)<div><dt>Concluído em</dt><dd>@dataHora($pc->concluido_em)</dd></div>@endif
      @if ($pc->observacao)<div><dt>Observação</dt><dd>{{ $pc->observacao }}</dd></div>@endif
    </dl>
    @if ($pc->mensagem)<p class="aviso {{ $pc->status === 'erro' ? 'erro' : '' }} pc-mensagem">{{ $pc->mensagem }}</p>@endif
    <div class="rolagem">
    <table class="tabela pc-itens">
      <thead><tr><th>Item</th><th>Produto</th><th class="num">Quantidade</th><th class="num">{{ $pc->ehSolicitacao() ? 'Em pedido' : 'Entregue' }}</th>
        @if ($pc->ehSolicitacao())<th>Pedido</th>@endif<th class="num">Valor unit.</th><th class="num">Total</th></tr></thead>
      <tbody>
      @foreach ($pc->itens->sortBy('item') as $it)
        <tr>
          <td class="suave">{{ $it->item }}</td>
          <td><code>{{ $it->produto_codigo }}</code> {{ $it->produto_descricao }}@if ($it->observacao)<br><small class="suave">{{ $it->observacao }}</small>@endif</td>
          <td class="num">{{ SolicitacaoMaterialItem::quantidade($it->quantidade) }} {{ $it->um }}</td>
          <td class="num">{{ $it->quantidade_entregue !== null ? SolicitacaoMaterialItem::quantidade($it->quantidade_entregue) : '—' }}</td>
          @if ($pc->ehSolicitacao())<td>{{ $it->pedido_gerado ?? '—' }}</td>@endif
          <td class="num">{{ $it->valor_unitario !== null ? CustoOrdemServico::reais((float) $it->valor_unitario) : '—' }}</td>
          <td class="num">{{ $it->valor_total !== null ? CustoOrdemServico::reais((float) $it->valor_total) : '—' }}</td>
        </tr>
      @endforeach
      </tbody>
    </table>
    </div>
    @if ($podeAdministrar && ($pc->podeCancelar() || $pc->status === 'erro'))
      <div class="pc-acoes">
        @if ($pc->status === 'erro')
          <form method="post" action="{{ route('os.pedidos-compra.reenviar', $pc) }}">@csrf
            <button class="botao pequeno" type="submit">Reenviar ao Protheus</button></form>
        @endif
        <form method="post" action="{{ route('os.pedidos-compra.cancelar', $pc) }}" onsubmit="return confirm('Cancelar {{ $pc->numeroFormatado() }}?')">@csrf
          <button class="botao pequeno secundario" type="submit">Cancelar</button></form>
      </div>
    @endif
  </article>
@empty
  <p class="suave">Nenhum pedido ou solicitação de compra gerado.</p>
@endforelse
