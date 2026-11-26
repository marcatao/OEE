{{-- Resultado da tela Pedidos de venda (trocado sem recarregar a página: ?parcial=1). Variáveis: $pedidos (paginados),
     $cartoes, $totais, $filtro. --}}
@php($moeda = fn ($v) => \App\Support\Formato::moeda((float) $v))
@php($etapas = \App\Services\Faturamento\EtapasPedidoVenda::ETAPAS)
@php($linkEtapa = fn (?string $e) => route('faturamento.pedidos.index', $filtro->parametros(['etapas' => $e ? [$e] : null, 'page' => null])))

<section class="cartao">
  <h2 style="margin-top:0">Pedidos por etapa <small class="suave">{{ $filtro->rotuloMes() }} · clique para filtrar a lista</small></h2>
  <p class="suave"><small>Em aberto: <strong>{{ $totais['abertos'] }}</strong> {{ $totais['abertos'] === 1 ? 'pedido' : 'pedidos' }}
    · <strong>{{ $moeda($totais['valor_aberto']) }}</strong> a faturar ({{ mb_strtolower(\App\Services\Faturamento\FiltroPedidosVenda::ABERTOS[$filtro->abertos]) }}).</small></p>
  <div class="pv-cartoes">
    @foreach ($etapas as $chave => $e)
      @php($ativo = $filtro->etapas === [$chave])
      <a class="tempo-cartao pv-cartao pv-{{ $chave }} @if ($ativo) ativo @endif" href="{{ $linkEtapa($ativo ? null : $chave) }}"
         data-parcial data-etapa="{{ $ativo ? '' : $chave }}" @if ($ativo) aria-current="true" @endif>
        <span class="pv-etapa pv-{{ $chave }}">{{ $e['rotulo'] }}</span>
        <strong>{{ $cartoes[$chave]['quantidade'] }}</strong>
        <span class="pv-valor">{{ $moeda($cartoes[$chave]['valor']) }}</span>
        <small>{{ $e['descricao'] }}</small>
      </a>
    @endforeach
  </div>
</section>

<section class="cartao">
  <h2 style="margin-top:0">Pedidos <small class="suave">{{ $pedidos->total() }} no filtro</small></h2>
  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Pedido</th><th>Cliente</th><th>Emissão</th><th>Entrega prevista</th><th class="num">Valor</th><th>Etapa</th><th class="num">Dias na etapa</th></tr></thead>
    <tbody>
    @forelse ($pedidos as $p)
      @php($dias = $p->diasNaEtapa())
      @php($atrasado = $p->emAberto() && trim($p->entrega_prevista) !== '' && $p->entrega_prevista < now(\App\Services\Faturamento\FiltroPedidosVenda::fuso())->format('Ymd'))
      <tr>
        <td><code>{{ $p->numero() }}</code>@if (trim($p->c5_filial) !== '')<br><small class="suave">filial {{ $p->c5_filial }}</small>@endif</td>
        <td class="quebra">{{ trim($p->cliente_nome) ?: 'Cliente '.$p->c5_cliente }}<br><small class="suave">{{ $p->c5_cliente }}@if (trim($p->c5_lojacli) !== '') / {{ $p->c5_lojacli }}@endif</small></td>
        <td>{{ \App\Models\Faturamento\PedidoVendaEtapa::data($p->c5_emissao) }}</td>
        <td>{{ \App\Models\Faturamento\PedidoVendaEtapa::data($p->entrega_prevista) }}
          @if ($atrasado)<br><small class="erro">atrasado</small>@endif
          @if (! $p->emAberto() && trim($p->faturado_em) !== '')<br><small class="suave">faturado {{ \App\Models\Faturamento\PedidoVendaEtapa::data($p->faturado_em) }}</small>@endif</td>
        <td class="num" title="Total do pedido: {{ $moeda($p->valor_total) }}">{{ $moeda($p->valor()) }}
          <br><small class="suave">{{ $p->emAberto() ? 'a faturar' : 'faturado' }}</small></td>
        <td><span class="pv-etapa pv-{{ $p->etapa }}">{{ $p->rotuloEtapa() }}</span>
          @if ($p->parcial)<br><small class="suave">parcialmente faturado</small>@endif</td>
        <td class="num">{{ $dias === null ? '-' : $dias }}</td>
      </tr>
    @empty
      <tr><td colspan="7" class="suave">Nenhum pedido no filtro. Os pedidos vêm do Protheus (SC5, SC6 e SC9, pela procedure): sincronize "Pedidos de venda" em Segurança › Banco de dados.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  @if ($pedidos->hasPages())
    <nav class="paginacao" aria-label="Paginação">
      @if ($pedidos->onFirstPage())<span>‹ Anterior</span>@else<a href="{{ $pedidos->previousPageUrl() }}" data-parcial>‹ Anterior</a>@endif
      <span>Página {{ $pedidos->currentPage() }} de {{ $pedidos->lastPage() }}</span>
      @if ($pedidos->hasMorePages())<a href="{{ $pedidos->nextPageUrl() }}" data-parcial>Próxima ›</a>@else<span>Próxima ›</span>@endif
    </nav>
  @endif
</section>
