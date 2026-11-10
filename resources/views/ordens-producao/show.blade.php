@extends('layouts.portal')

@section('titulo', 'OP '.$op->numero)

@use('App\Models\OrdemProducao')
@use('App\Support\Formato')

@section('conteudo')
@php
  $produto = $produtos[rtrim($op->c2_produto)] ?? null;
  $unitario = $resumo->custoUnitario();
  $divergencia = $resumo->divergencia();
  $metaTotal = $resumo->custoMetaTotal();
  $custoLiquido = $resumo->custoLiquido();
@endphp
<section class="cartao">
  <p class="trilha"><a href="{{ route('ordens-producao.index') }}">Ordens de produção</a></p>
  <h1>OP <code>{{ $op->numero }}</code> <span class="etiqueta op-{{ $op->status }}">{{ $op->rotuloStatus() }}</span></h1>
  <p><code>{{ $op->c2_produto }}</code> {{ $produto?->descricao() ?? 'produto não sincronizado' }}
    · Quantidade {{ Formato::quantidade($op->c2_quant) }} {{ $op->c2_um }} · Produzido no Protheus {{ Formato::quantidade($op->c2_quje) }}
    · Emissão {{ OrdemProducao::data($op->c2_emissao) }} · Previsão {{ OrdemProducao::data($op->c2_datpri) }} a {{ OrdemProducao::data($op->c2_datprf) }}
    @if (trim($op->c2_datrf) !== '') · Encerrada em {{ OrdemProducao::data($op->c2_datrf) }}@endif
    @if (trim($op->c2_filial) !== '') · Filial {{ $op->c2_filial }}@endif
    · {{ $apontamentos }} {{ $apontamentos === 1 ? 'apontamento real' : 'apontamentos reais' }}</p>

  <h3>Resumo de custo</h3>
  <div class="custo-resumo">
    <div class="destaque"><span>Custo por peça (atual)</span><strong>{{ Formato::moeda($unitario, 4) }} <small>/{{ $op->c2_um ?: 'un' }}</small></strong>
      <small>{{ $unitario === null ? 'sem produção apontada' : 'consumos ÷ produzido nesta OP' }}</small></div>
    <div><span>Custo por peça (meta)</span><strong>{{ Formato::moeda($resumo->meta, 4) }} <small>/{{ $op->c2_um ?: 'un' }}</small></strong>
      <small>fechamento mais recente do produto (SB9)</small></div>
    <div><span>Divergência por peça</span><strong class="{{ Formato::nivelDivergencia($divergencia) }}">{{ Formato::divergencia($divergencia) }}</strong>
      @if ($unitario !== null && $resumo->meta !== null)<small>{{ Formato::moeda($unitario - $resumo->meta, 4) }} por peça</small>@endif</div>
    <div><span>Produzido (PR)</span><strong>{{ Formato::quantidade($resumo->producaoQtd) }} <small>{{ $op->c2_um }}</small></strong>
      <small>valor no Protheus {{ Formato::moeda($resumo->producaoValor) }}</small></div>
    <div><span>Consumos da OP</span><strong>{{ Formato::moeda($custoLiquido) }}</strong><small>baixas + notas − devoluções</small></div>
    <div><span>Baixas (RE)</span><strong>{{ Formato::moeda($resumo->consumoValor) }}</strong>
      @if ($resumo->pelaMedia > 0)<small>{{ $resumo->pelaMedia }} pelo custo médio de estoque</small>@endif</div>
    <div><span>Notas com OP (SD2)</span><strong>{{ Formato::moeda($resumo->notaValor) }}</strong><small>{{ Formato::quantidade($resumo->notaQtd) }} itens</small></div>
    <div><span>Devoluções (DE)</span><strong>{{ Formato::moeda($resumo->devolucaoValor) }}</strong></div>
  </div>
  @if ($resumo->semCusto > 0)
    <p class="texto-alerta">{{ $resumo->semCusto }} {{ $resumo->semCusto === 1 ? 'consumo está' : 'consumos estão' }} sem custo no Protheus e sem custo médio de
      estoque (SB2): {{ $resumo->semCusto === 1 ? 'entrou' : 'entraram' }} com zero no custo da peça.</p>
  @endif

  @if ($componentes)
    <h3 style="margin-top:1.5rem">Consumo por componente</h3>
    <div class="rolagem">
    <table class="tabela">
      <thead><tr><th>Componente</th><th class="num">Quantidade líquida</th><th class="num">Valor líquido</th><th class="num">% do custo</th>
        <th class="num">Por peça produzida</th></tr></thead>
      <tbody>
      @foreach ($componentes as $c)
        <tr>
          <td class="quebra"><code>{{ $c['produto'] }}</code> <small>{{ ($produtos[$c['produto']] ?? null)?->descricao() }}</small></td>
          <td class="num">{{ Formato::quantidade($c['quantidade']) }} <small class="suave">{{ $c['um'] }}</small></td>
          <td class="num">{{ Formato::moeda($c['valor']) }}</td>
          <td class="num">{{ Formato::pct(abs($custoLiquido) > 0.000001 ? $c['valor'] / $custoLiquido : null) }}</td>
          <td class="num">{{ Formato::moeda($resumo->producaoQtd > 0 ? $c['valor'] / $resumo->producaoQtd : null, 4) }}</td>
        </tr>
      @endforeach
      </tbody>
    </table>
    </div>
  @endif

  <h3 style="margin-top:1.5rem">Kardex da OP</h3>
  <p class="suave">Movimentos válidos da OP em ordem de data. Entradas: produção e devoluções; saídas: baixas e notas fiscais. Valorização:
    <em>Protheus</em> = custo gravado no movimento (D3_CUSTO1 / D2_CUSTO1); <em>custo médio</em> = o Protheus ainda não valorizou, usado
    quantidade × custo médio de estoque (SB2). Os acumulados mostram como o custo por peça evoluiu a cada movimento.</p>
  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Data</th><th>Movimento</th><th>Produto</th><th>Documento</th><th class="num">Entrada</th><th class="num">Saída</th>
      <th class="num">Valor</th><th>Valorização</th><th class="num">Produzido acum.</th><th class="num">Consumos acum.</th><th class="num">Custo por peça acum.</th></tr></thead>
    <tbody>
    @forelse ($linhas as $m)
      <tr>
        <td>{{ OrdemProducao::data($m->emissao) }}</td>
        <td><span class="etiqueta mov-{{ $m->tipo }}">{{ $tipos->rotulo($m->tipo) }}</span><br>
          <small class="suave">@if ($m->tipo !== 'nota_fiscal'){{ $m->cf }} · @endif{{ $m->descricao_cf }}</small></td>
        <td class="quebra"><code>{{ $m->produto }}</code> <small>{{ ($produtos[rtrim($m->produto)] ?? null)?->descricao() }}</small></td>
        <td>{{ $m->documento }}@if (trim($m->serie) !== '') <small class="suave">série {{ $m->serie }}</small>@endif</td>
        <td class="num kx-entrada">@if (in_array($m->tipo, ['producao', 'devolucao'], true)){{ Formato::quantidade((float) $m->quantidade) }} <small>{{ $m->um }}</small>@endif</td>
        <td class="num kx-saida">@if (in_array($m->tipo, ['consumo', 'nota_fiscal'], true)){{ Formato::quantidade((float) $m->quantidade) }} <small>{{ $m->um }}</small>@endif</td>
        <td class="num">{{ Formato::moeda((float) $m->custo) }}</td>
        <td>@if ($m->tipo === 'producao')<span class="suave">—</span>@elseif ($m->valorizacao === 'protheus')<small>Protheus</small>@elseif ($m->valorizacao === 'medio')<small title="{{ Formato::quantidade((float) $m->quantidade) }} × {{ Formato::moeda((float) $m->custo_medio, 4) }}">custo médio</small>@else<small class="texto-alerta">sem custo</small>@endif</td>
        <td class="num">{{ Formato::quantidade($m->produzido_acumulado) }}</td>
        <td class="num">{{ Formato::moeda($m->custo_acumulado) }}</td>
        <td class="num">{{ Formato::moeda($m->unitario_acumulado, 4) }}</td>
      </tr>
    @empty
      <tr><td colspan="11" class="suave">Nenhum movimento desta OP no Protheus (SD3/SD2) até a última sincronização.</td></tr>
    @endforelse
    </tbody>
    @if ($linhas)
      <tfoot><tr><td colspan="4">Totais ({{ count($linhas) }} movimentos)</td>
        <td></td><td></td>{{-- entradas e saídas misturam unidades (UN, KG...): sem total --}}
        <td></td><td></td><td class="num">{{ Formato::quantidade($resumo->producaoQtd) }}</td>
        <td class="num">{{ Formato::moeda($custoLiquido) }}</td><td class="num">{{ Formato::moeda($unitario, 4) }}</td></tr></tfoot>
    @endif
  </table>
  </div>
</section>
@endsection
