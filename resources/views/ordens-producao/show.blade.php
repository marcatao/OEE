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
    · {{ $apontamentos }} {{ $apontamentos === 1 ? 'apontamento real' : 'apontamentos reais' }}
    @if ($roteiro['codigo']) · Roteiro {{ $roteiro['codigo'] }}@endif
    @if (trim($op->c2_revisao) !== '') · Revisão da estrutura {{ $op->c2_revisao }}@endif</p>
  @if ($roteiro['ferramentas_codigos'])
    <p class="op-molde">@include('layouts.partials.icone', ['nome' => 'ferramenta'])
      <strong>{{ count($roteiro['ferramentas_codigos']) === 1 ? 'Ferramenta / molde' : 'Ferramentas / moldes' }}:</strong>
      @foreach ($roteiro['ferramentas_codigos'] as $cod)
        @php $f = $roteiro['ferramentas'][$cod] ?? null; @endphp
        <span class="etiqueta"><code>{{ $cod }}</code>@if ($f) {{ $f->h4_descri }} · {{ $f->h4_qtdcavativas }}/{{ $f->h4_qtdcav }} cavidades @endif</span>
      @endforeach
    </p>
  @endif

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

  {{-- Empenhos (SD4): logo abaixo do kardex --}}
  <h3 style="margin-top:1.5rem" id="empenhos">Empenhos da OP</h3>
  <p class="suave">Materiais reservados para esta OP no Protheus (SD4). Empenhado = quantidade original (D4_QTDEORI); saldo = o que
    ainda falta baixar (D4_QUANT), que cai conforme as baixas (RE) do kardex.</p>
  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Componente</th><th>Seq. (TRT)</th><th>Armazém</th><th>Lote</th><th>Data</th><th class="num">Empenhado</th>
      <th class="num">Baixado</th><th class="num">Saldo</th><th class="num">Atendido</th></tr></thead>
    <tbody>
    @forelse ($empenhos as $e)
      @php $pe = $produtosEngenharia[rtrim($e->d4_cod)] ?? null; @endphp
      <tr>
        <td class="quebra"><code>{{ $e->d4_cod }}</code> <small>{{ $pe?->descricao() ?? 'produto não sincronizado' }}</small></td>
        <td>{{ $e->d4_trt ?: '-' }}</td>
        <td>{{ $e->d4_local }}</td>
        <td>{{ $e->d4_lotectl ?: '-' }}</td>
        <td>{{ OrdemProducao::data($e->d4_data) }}</td>
        <td class="num">{{ Formato::quantidade($e->d4_qtdeori) }} <small class="suave">{{ $pe?->b1_um }}</small></td>
        <td class="num">{{ Formato::quantidade($e->baixado()) }}</td>
        <td class="num">{{ Formato::quantidade($e->d4_quant) }}</td>
        <td class="num">{{ Formato::pct($e->atendido()) }}</td>
      </tr>
    @empty
      <tr><td colspan="9" class="suave">Nenhum empenho desta OP no Protheus (SD4) até a última sincronização.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>

  {{-- Estrutura atual (SG1) --}}
  <h3 style="margin-top:1.5rem" id="estrutura">Estrutura atual do produto</h3>
  <p class="suave">Componentes de <code>{{ $op->c2_produto }}</code> na estrutura do Protheus (SG1) vigente hoje
    @if (trim($op->c2_revisao) !== '') na revisão {{ $op->c2_revisao }} da OP @endif (1º nível). Necessidade da OP = quantidade por peça ×
    {{ Formato::quantidade($op->c2_quant) }} (quantidade da OP; componente de quantidade fixa não multiplica). Empenhado: o que está na SD4 para o componente.</p>
  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Seq. (TRT)</th><th>Componente</th><th class="num">Qtd. por peça</th><th class="num">Perda %</th><th class="num">Necessidade da OP</th>
      <th class="num">Empenhado</th><th class="num">Diferença</th><th>Vigência</th></tr></thead>
    <tbody>
    @forelse ($estrutura as $g)
      @php $pg = $produtosEngenharia[rtrim($g->g1_comp)] ?? null; @endphp
      @php $dif = $g->empenhado === null ? null : $g->empenhado - $g->necessidade; @endphp
      <tr>
        <td>{{ $g->g1_trt ?: '-' }}</td>
        <td class="quebra"><code>{{ $g->g1_comp }}</code> <small>{{ $pg?->descricao() ?? 'produto não sincronizado' }}</small>
          @if ($g->tem_estrutura)<br><small class="suave">tem estrutura própria (intermediário)
            @if (auth()->user()->pode('estruturas')) · <a href="{{ route('estruturas.index', ['produto' => rtrim($g->g1_comp)]) }}">ver</a>@endif</small>@endif</td>
        <td class="num">{{ Formato::quantidade($g->g1_quant) }} <small class="suave">{{ $pg?->b1_um }}</small>@if (strtoupper(trim($g->g1_fixvar)) === 'F')<br><small class="suave">fixa</small>@endif</td>
        <td class="num">{{ $g->g1_perda > 0 ? Formato::quantidade($g->g1_perda) : '-' }}</td>
        <td class="num">{{ Formato::quantidade($g->necessidade) }}</td>
        <td class="num">{{ $g->empenhado === null ? '—' : Formato::quantidade($g->empenhado) }}</td>
        <td class="num">@if ($dif === null)<small class="texto-alerta">sem empenho</small>@elseif (abs($dif) < 0.0001)<small class="suave">igual</small>@else<span class="{{ $dif > 0 ? 'texto-alerta' : '' }}">{{ $dif > 0 ? '+' : '' }}{{ Formato::quantidade($dif) }}</span>@endif</td>
        <td><small>{{ OrdemProducao::data($g->g1_ini) ?: '-' }} a {{ OrdemProducao::data($g->g1_fim) ?: '-' }}</small></td>
      </tr>
    @empty
      <tr><td colspan="8" class="suave">Nenhuma estrutura vigente para este produto na SG1 (ou ainda não sincronizada).</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>

  {{-- Roteiro (SG2) e ferramentas (SH4) --}}
  <h3 style="margin-top:1.5rem" id="roteiro">Roteiro de operações</h3>
  <p class="suave">
    @if ($roteiro['codigo'])
      Roteiro <strong>{{ $roteiro['codigo'] }}</strong> do produto na SG2
      {{ $roteiro['informado'] ? '(informado na OP, C2_ROTEIRO)' : '(a OP não informa o roteiro: usado o padrão do produto)' }}.
      @if ($roteiro['outros']) O produto também tem {{ count($roteiro['outros']) === 1 ? 'o roteiro' : 'os roteiros' }} {{ implode(', ', $roteiro['outros']) }}. @endif
      Centro de trabalho = máquina; ferramenta = molde/ferramenta da SH4. Tempos como estão no Protheus.
    @else
      O produto não tem roteiro na SG2 (ou ainda não foi sincronizado).
    @endif
  </p>
  @if ($roteiro['operacoes']->isNotEmpty())
    <div class="rolagem">
    <table class="tabela">
      <thead><tr><th>Operação</th><th>Centro de trabalho (máquina)</th><th>Recurso</th><th>Ferramenta / molde</th><th>Tipo</th>
        <th class="num">Setup</th><th class="num">Tempo padrão</th><th class="num">Lote padrão</th><th class="num">Mão de obra</th></tr></thead>
      <tbody>
      @foreach ($roteiro['operacoes'] as $o)
        <tr>
          <td class="quebra"><code>{{ $o->g2_operac }}</code> {{ $o->g2_descri }}</td>
          <td class="quebra">@if (trim($o->g2_ctrab) !== '')<code>{{ $o->g2_ctrab }}</code>
            @if ($m = $roteiro['maquinas'][rtrim($o->g2_ctrab)] ?? null)<br><small>{{ $m->nome }}{{ $m->ordem_producao_id === $op->id ? ' · com esta OP' : '' }}</small>@endif @else - @endif</td>
          <td>{{ $o->g2_recurso ?: '-' }}</td>
          <td class="quebra">@if (trim($o->g2_ferram) !== '')<code>{{ $o->g2_ferram }}</code>
            @if ($f = $roteiro['ferramentas'][rtrim($o->g2_ferram)] ?? null)<br><small>{{ $f->h4_descri }} · {{ $f->h4_qtdcavativas }}/{{ $f->h4_qtdcav }} cavidades</small>
            @else<br><small class="suave">não encontrada na SH4</small>@endif @else - @endif</td>
          <td>{{ $o->rotuloTipo() }}</td>
          <td class="num">{{ Formato::quantidade($o->g2_setup) }}</td>
          <td class="num">{{ Formato::quantidade($o->g2_tempad) }}</td>
          <td class="num">{{ Formato::quantidade($o->g2_lotepad) }}</td>
          <td class="num">{{ Formato::quantidade($o->g2_maoobra) }}</td>
        </tr>
      @endforeach
      </tbody>
    </table>
    </div>
  @endif

  @if ($roteiro['ferramentas']->isNotEmpty())
    <h3 style="margin-top:1.5rem" id="ferramentas">Ferramenta / molde da OP</h3>
    <div class="rolagem">
    <table class="tabela">
      <thead><tr><th>Ferramenta</th><th class="num">Cavidades (Protheus)</th><th class="num">Cavidades ativas</th><th class="num">Quantidade</th>
        <th class="num">Vida útil</th><th>Operações</th></tr></thead>
      <tbody>
      @foreach ($roteiro['ferramentas'] as $cod => $f)
        <tr>
          <td class="quebra"><code>{{ $cod }}</code> {{ $f->h4_descri }}</td>
          <td class="num">{{ $f->h4_qtdcav }}</td>
          <td class="num">{{ $f->h4_qtdcavativas }}@if ($f->cavidadesAcima())<br><small class="texto-alerta">acima do Protheus</small>@endif</td>
          <td class="num">{{ Formato::quantidade($f->h4_quant) }}</td>
          <td class="num">{{ $f->h4_vidauti > 0 ? Formato::quantidade($f->h4_vidauti) : '-' }}</td>
          <td>{{ $roteiro['operacoes']->filter(fn ($o) => rtrim($o->g2_ferram) === $cod)->pluck('g2_operac')->join(', ') }}</td>
        </tr>
      @endforeach
      </tbody>
    </table>
    </div>
  @endif
</section>
@endsection
