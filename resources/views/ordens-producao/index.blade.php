@extends('layouts.portal')

@section('titulo', 'Ordens de produção')

@use('App\Models\OrdemProducao')
@use('App\Support\Formato')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Produção</p>
  <h1>Ordens de produção</h1>
  <p class="suave">OPs do Protheus (tabela SC2), só para consulta.
    @if ($ultimaSincronizacao) Última sincronização: @dataHora($ultimaSincronizacao). @else Ainda não sincronizadas. @endif @include('partials.proxima-sincronizacao', ['chave' => 'ordens_producao'])
    O <strong>status</strong> é calculado pelo portal: <em>fechada</em> (com data de encerramento, C2_DATRF, no Protheus), <em>parada</em> (suspensa no
    Protheus ou com apontamento de parada em aberto), <em>andamento</em> (com produção ou apontamentos) ou <em>aberta</em>.
    <strong>Custo unit. atual</strong> = custo de <strong>1 peça</strong> nesta OP: consumos da OP (baixas da SD3 + notas com OP da SD2 −
    devoluções) ÷ quantidade produzida na própria OP. Cada consumo vale o custo do Protheus (D3_CUSTO1 / D2_CUSTO1) ou, enquanto ele
    estiver zerado, quantidade × custo médio de estoque (SB2). <strong>Custo unit. meta</strong> = custo de 1 peça no fechamento mais recente
    do produto (SB9). Clique na OP para ver o kardex.</p>

  <div class="op-totais">
    @foreach (OrdemProducao::STATUS as $chave => $rotulo)
      <a href="{{ route('ordens-producao.index', ['status' => [$chave]]) }}" @class(['op-total', 'ativo' => $status === [$chave]])>
        <span class="etiqueta op-{{ $chave }}">{{ $rotulo }}</span> <strong>{{ number_format($totais[$chave] ?? 0, 0, ',', '.') }}</strong>
      </a>
    @endforeach
  </div>

  <form method="get" class="filtros">
    <label>Buscar <input name="busca" value="{{ $busca }}" placeholder="Número da OP, código ou descrição do produto"></label>
    <fieldset class="op-filtro-status">
      <legend>Status</legend>
      @foreach (OrdemProducao::STATUS as $chave => $rotulo)
        <label class="opcao"><input type="checkbox" name="status[]" value="{{ $chave }}" @checked(in_array($chave, $status, true))> <span>{{ $rotulo }}</span></label>
      @endforeach
    </fieldset>
    <button class="botao secundario" type="submit">Filtrar</button>
    @if ($busca !== '' || $status)<a href="{{ route('ordens-producao.index') }}">Limpar</a>@endif
  </form>

  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>OP</th><th>Status</th><th>Produto</th><th class="num">Quantidade</th><th class="num">Produzido</th><th class="num">Perda</th>
      <th class="num" title="Baixas da OP rateadas nas produções pelas peças">Custo simulado (OEE)</th><th class="num">Custo unit. meta</th><th class="num" title="Simulado × meta">Divergência</th>
      <th>Emissão</th><th>Previsão</th><th>Encerrada em</th><th class="num">Apontamentos</th></tr></thead>
    <tbody>
    @forelse ($ordens as $op)
      @php($produto = $produtos[$op->c2_produto] ?? null)
      <tr>
        <td><a href="{{ route('ordens-producao.show', $op) }}" title="Detalhes e kardex da OP"><code>{{ $op->numero }}</code></a>@if (trim($op->c2_filial) !== '')<br><small class="suave">filial {{ $op->c2_filial }}</small>@endif</td>
        <td><span class="etiqueta op-{{ $op->status }}">{{ $op->rotuloStatus() }}</span>@if ($op->c2_tpop === 'P')<br><small class="suave">prevista</small>@endif</td>
        <td class="quebra"><code>{{ $op->c2_produto }}</code><br><small>{{ $produto?->descricao() ?? 'produto não sincronizado' }}</small></td>
        <td class="num">{{ \App\Support\Formato::quantidade((float) $op->c2_quant) }} <small class="suave">{{ $op->c2_um ?: $produto?->b1_um }}</small></td>
        @php($produzidoPr = $custos[$op->id]->producaoQtd)
        <td class="num" title="Soma dos apontamentos de produção (PR); C2_QUJE no Protheus: {{ \App\Support\Formato::quantidade((float) $op->c2_quje) }}">{{ \App\Support\Formato::quantidade($produzidoPr) }}
          @if ($op->c2_quant > 0)<br><small class="suave">{{ number_format(min($produzidoPr / $op->c2_quant, 9.99) * 100, 0, ',', '.') }}%</small>@endif</td>
        <td class="num">{{ \App\Support\Formato::quantidade((float) $op->c2_perda) }}</td>
        <td class="num">{{ Formato::moeda($custos[$op->id]->custoSimulado(), 4) }} <small class="suave">/{{ $op->c2_um ?: 'un' }}</small>
          @if ($custos[$op->id]->pelaMedia > 0)<br><small class="suave" title="Consumos que o Protheus ainda não valorizou: usado o custo médio de estoque (SB2)">{{ $custos[$op->id]->pelaMedia }} pelo custo médio</small>@endif
          @if ($custos[$op->id]->semCusto > 0)<br><small class="texto-alerta">{{ $custos[$op->id]->semCusto }} sem custo</small>@endif</td>
        <td class="num">{{ Formato::moeda($op->custo_meta, 4) }} <small class="suave">/{{ $op->c2_um ?: 'un' }}</small></td>
        <td class="num {{ Formato::nivelDivergencia($custos[$op->id]->divergencia()) }}">{{ Formato::divergencia($custos[$op->id]->divergencia()) }}</td>
        <td>{{ OrdemProducao::data($op->c2_emissao) }}</td>
        <td>{{ OrdemProducao::data($op->c2_datpri) }} a {{ OrdemProducao::data($op->c2_datprf) }}</td>
        <td>{{ OrdemProducao::data($op->c2_datrf) }}</td>
        <td class="num">{{ $op->apontamentos_count }}</td>
      </tr>
    @empty
      <tr><td colspan="13" class="suave">Nenhuma ordem de produção encontrada. As OPs vêm do Protheus: sincronize em Administração › Banco de dados.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  {{ $ordens->links('partials.paginacao') }}
</section>
@endsection
