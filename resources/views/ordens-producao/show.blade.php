@extends('layouts.portal')

@section('titulo', 'OP '.$op->numero)

@use('App\Models\OrdemProducao')
@use('App\Support\Formato')

@section('conteudo')
@php
  $produto = $produtos[rtrim($op->c2_produto)] ?? null;
  $unitario = $resumo->custoSimulado();
  $custoProtheus = $resumo->custoProtheus();
  $divergencia = $resumo->divergencia();
  $metaTotal = $resumo->custoMetaTotal();
  $custoLiquido = $resumo->custoLiquido();
@endphp
@php
  $u = auth()->user();
  $podeEditarCavidades = $u->ehAdmin();
  $abaInicial = $errors->has('h4_qtdcavativas') ? 'ferramenta' : 'op';
@endphp
<section class="cartao">
  <p class="trilha"><a href="{{ route('ordens-producao.index') }}">Ordens de produção</a></p>
  <div class="op-cabecalho">
    <div>
      <h1>OP <code>{{ $op->numero }}</code> <span class="etiqueta op-{{ $op->status }}">{{ $op->rotuloStatus() }}</span></h1>
      <p class="op-produto"><code>{{ $op->c2_produto }}</code> {{ $produto?->descricao() ?? 'produto não sincronizado' }}</p>
    </div>
    @if ($roteiro['ferramentas_codigos'])
      <p class="op-molde">@include('layouts.partials.icone', ['nome' => 'ferramenta'])
        <strong>{{ count($roteiro['ferramentas_codigos']) === 1 ? 'Molde' : 'Moldes' }}:</strong>
        @foreach ($roteiro['ferramentas_codigos'] as $cod)
          @php $f = $roteiro['ferramentas'][$cod] ?? null; @endphp
          <span><code>{{ $cod }}</code>@if ($f) {{ $f->h4_descri }} · {{ $f->h4_qtdcavativas }}/{{ $f->h4_qtdcav }} cav.@endif</span>
        @endforeach
      </p>
    @endif
  </div>

  <div class="abas" data-abas data-aba-inicial="{{ $abaInicial }}">
    <div class="abas-lista" role="tablist" aria-label="Informações da OP">
      <button type="button" role="tab" data-aba="op">OP</button>
      <button type="button" role="tab" data-aba="apontamentos">Apontamentos reais <span class="abas-contador">{{ $apontamentosReais->count() }}</span></button>
      <button type="button" role="tab" data-aba="kardex">Kardex <span class="abas-contador">{{ count($linhas) }}</span></button>
      <button type="button" role="tab" data-aba="empenhos">Empenhos <span class="abas-contador">{{ $empenhos->count() }}</span></button>
      <button type="button" role="tab" data-aba="estrutura">Estrutura <span class="abas-contador">{{ $estrutura->count() }}</span></button>
      <button type="button" role="tab" data-aba="roteiro">Roteiro <span class="abas-contador">{{ $roteiro['operacoes']->count() }}</span></button>
      <button type="button" role="tab" data-aba="ferramenta">Ferramenta <span class="abas-contador">{{ count($roteiro['ferramentas_codigos']) }}</span></button>
    </div>

    {{-- OP: dados da SC2 e custo --}}
    <div class="abas-painel" role="tabpanel" data-painel="op">
      <dl class="op-ficha">
        <div><dt>Quantidade</dt><dd>{{ Formato::quantidade($op->c2_quant) }} {{ $op->c2_um }}</dd></div>
        <div><dt>Produzido no Protheus</dt><dd>{{ Formato::quantidade($op->c2_quje) }} {{ $op->c2_um }}
          @if ($op->c2_quant > 0)<small>{{ Formato::pct($op->c2_quje / $op->c2_quant) }}</small>@endif</dd></div>
        <div><dt>Perda</dt><dd>{{ Formato::quantidade($op->c2_perda) }}</dd></div>
        <div><dt>Emissão</dt><dd>{{ OrdemProducao::data($op->c2_emissao) ?: '-' }}</dd></div>
        <div><dt>Previsão</dt><dd>{{ OrdemProducao::data($op->c2_datpri) ?: '-' }} a {{ OrdemProducao::data($op->c2_datprf) ?: '-' }}</dd></div>
        <div><dt>Encerrada em</dt><dd>{{ trim($op->c2_datrf) !== '' ? OrdemProducao::data($op->c2_datrf) : '-' }}</dd></div>
        <div><dt>Roteiro</dt><dd>{{ $roteiro['codigo'] ?: '-' }}@if ($roteiro['codigo'] && ! $roteiro['informado'])<small>padrão do produto</small>@endif</dd></div>
        <div><dt>Revisão da estrutura</dt><dd>{{ trim($op->c2_revisao) ?: '-' }}</dd></div>
        <div><dt>Armazém</dt><dd>{{ trim($op->c2_local) ?: '-' }}</dd></div>
        <div><dt>Centro de custo</dt><dd>{{ trim($op->c2_cc) ?: '-' }}</dd></div>
        <div><dt>Tipo</dt><dd>{{ ['F' => 'Firme', 'P' => 'Prevista'][trim($op->c2_tpop)] ?? (trim($op->c2_tpop) ?: '-') }}</dd></div>
        <div><dt>Filial</dt><dd>{{ trim($op->c2_filial) ?: '-' }}</dd></div>
        @if (trim($op->c2_obs) !== '')<div class="largo"><dt>Observação</dt><dd>{{ $op->c2_obs }}</dd></div>@endif
      </dl>

      <h3>Resumo de custo</h3>
      <div class="custo-resumo">
        <div><span>Custo Protheus</span><strong>{{ Formato::moeda($custoProtheus, 4) }} <small>/{{ $op->c2_um ?: 'un' }}</small></strong>
          <small>{{ $custoProtheus === null ? 'sem produção apontada' : 'valor das produções (PR) ÷ produzido' }}</small></div>
        <div><span>Custo meta (mês passado)</span><strong>{{ Formato::moeda($resumo->meta, 4) }} <small>/{{ $op->c2_um ?: 'un' }}</small></strong>
          <small>fechamento mais recente do produto (SB9)</small></div>
        <div class="destaque"><span>Custo simulado (OEE)</span><strong>{{ Formato::moeda($unitario, 4) }} <small>/{{ $op->c2_um ?: 'un' }}</small></strong>
          <small>{{ $unitario === null ? 'sem produção apontada' : 'baixas rateadas nas produções pelas peças' }}</small></div>
        <div title="Custo simulado (OEE) × custo meta"><span>Divergência (simulado × meta)</span><strong class="{{ Formato::nivelDivergencia($divergencia) }}">{{ Formato::divergencia($divergencia) }}</strong>
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

    </div>

    {{-- Apontamentos reais (os da Timeline) com esta OP --}}
    <div class="abas-painel" role="tabpanel" data-painel="apontamentos" hidden>
      <h3>Apontamentos reais</h3>
      <p class="suave">Estados das máquinas apontados com esta OP (os mesmos da Timeline).
        @if ($apontamentosReais->isNotEmpty()) Tempo total: <strong>{{ Formato::minutos($apontamentosReais->sum(fn ($a) => $a->duracaoMin())) }}</strong>
          · produzindo: <strong>{{ Formato::minutos($apontamentosReais->filter(fn ($a) => $a->legenda?->tipo === 'produzindo')->sum(fn ($a) => $a->duracaoMin())) }}</strong>.@endif</p>
      <div class="rolagem">
      <table class="tabela">
        <thead><tr><th>Dia</th><th>Máquina</th><th>Legenda</th><th>Início</th><th>Fim</th><th class="num">Duração</th><th>Referência / observação</th>
          @if ($u->pode('apontamentos'))<th></th>@endif</tr></thead>
        <tbody>
        @forelse ($apontamentosReais as $a)
          @php
            $ini = $a->inicio->copy()->setTimezone(config('oee.fuso_exibicao'));
            $fim = $a->fim?->copy()->setTimezone(config('oee.fuso_exibicao'));
          @endphp
          <tr>
            <td>{{ $ini->format('d/m/Y') }}</td>
            <td class="quebra">{{ $a->maquina?->nome ?? 'máquina de outra unidade' }}@if ($a->maquina)<br><small class="suave">{{ $a->maquina->codigo }}</small>@endif</td>
            <td class="quebra">@if ($a->legenda)<span class="tl-chip"><i class="tl-cor" style="background: {{ $a->legenda->cor }}"></i>{{ $a->legenda->codigo }}</span>
              <small>{{ $a->legenda->descricao }}</small>@endif</td>
            <td>{{ $ini->format('H:i:s') }}</td>
            <td>@if ($fim){{ $fim->format('H:i:s') }}@else<span class="etiqueta situacao-online">em aberto</span>@endif</td>
            <td class="num">{{ Formato::minutos($a->duracaoMin()) }}</td>
            <td class="quebra">{{ $a->referencia ?: '' }}@if ($a->observacao)<br><small class="suave">{{ $a->observacao }}</small>@endif
              @if (! $a->referencia && ! $a->observacao)<span class="suave">-</span>@endif</td>
            @if ($u->pode('apontamentos'))
              <td><a href="{{ route('apontamentos.edit', $a) }}">Editar</a></td>
            @endif
          </tr>
        @empty
          <tr><td colspan="8" class="suave">Nenhum apontamento real com esta OP.</td></tr>
        @endforelse
        </tbody>
      </table>
      </div>
    </div>

    <div class="abas-painel" role="tabpanel" data-painel="kardex" hidden>
      <h3>Kardex da OP</h3>
      <p class="suave">Movimentos válidos da OP em ordem de data. Entradas: produção e devoluções; saídas: baixas e notas fiscais. Valorização:
        <em>Protheus</em> = custo gravado no movimento (D3_CUSTO1 / D2_CUSTO1); <em>custo médio</em> = o Protheus ainda não valorizou, usado
        quantidade × custo médio de estoque (SB2). Os acumulados mostram como o custo por peça evoluiu a cada movimento. <em>Custo simulado (OEE)</em>: o total das baixas/consumos da OP
        repartido entre as produções, proporcional às peças de cada uma.</p>
      <div class="rolagem">
      <table class="tabela">
        <thead><tr><th>Data</th><th>Movimento</th><th>Produto</th><th>Documento</th><th class="num">Entrada</th><th class="num">Saída</th>
          <th class="num">Valor</th><th>Valorização</th><th class="num">Produzido acum.</th><th class="num">Consumos acum.</th><th class="num">Custo por peça acum.</th>
          <th class="num" title="Baixas da OP rateadas nesta produção pela quantidade de peças">Custo simulado (OEE)</th></tr></thead>
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
            <td class="num">@if ($m->tipo === 'producao'){{ Formato::moeda($m->custo_simulado) }}<br><small class="suave">{{ Formato::moeda($unitario, 4) }}/peça</small>@endif</td>
          </tr>
        @empty
          <tr><td colspan="12" class="suave">Nenhum movimento desta OP no Protheus (SD3/SD2) até a última sincronização.</td></tr>
        @endforelse
        </tbody>
        @if ($linhas)
          <tfoot><tr><td colspan="4">Totais ({{ count($linhas) }} movimentos)</td>
            <td></td><td></td>{{-- entradas e saídas misturam unidades (UN, KG...): sem total --}}
            <td></td><td></td><td class="num">{{ Formato::quantidade($resumo->producaoQtd) }}</td>
            <td class="num">{{ Formato::moeda($custoLiquido) }}</td><td class="num">{{ Formato::moeda($resumo->custoUnitario(), 4) }}</td>
            <td class="num">{{ Formato::moeda($resumo->consumoValor) }}<br><small class="suave">{{ Formato::moeda($unitario, 4) }}/peça</small></td></tr></tfoot>
        @endif
      </table>
      </div>

    </div>

    <div class="abas-painel" role="tabpanel" data-painel="empenhos" hidden>
      <h3>Empenhos da OP</h3>
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

    </div>

    <div class="abas-painel" role="tabpanel" data-painel="estrutura" hidden>
      <h3>Estrutura atual do produto</h3>
      <p class="suave">Componentes de <code>{{ $op->c2_produto }}</code> na estrutura do Protheus (SG1) vigente {{ trim($op->c2_revisao) !== '' ? 'hoje, na revisão '.trim($op->c2_revisao).' da OP' : 'hoje' }} (1º nível). Necessidade da OP = quantidade por peça ×
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
            <td class="num">@if ($dif === null)<small class="texto-alerta">sem empenho</small>@elseif (abs($dif) < 0.0000005)<small class="suave">igual</small>@else<span class="{{ $dif > 0 ? 'texto-alerta' : '' }}">{{ $dif > 0 ? '+' : '' }}{{ Formato::quantidade($dif) }}</span>@endif</td>
            <td><small>{{ trim($g->g1_ini) === '' && trim($g->g1_fim) === '' ? 'sem limite' : (OrdemProducao::data($g->g1_ini) ?: 'início').' a '.(OrdemProducao::data($g->g1_fim) ?: 'sem fim') }}</small></td>
          </tr>
        @empty
          <tr><td colspan="8" class="suave">Nenhuma estrutura vigente para este produto na SG1 (ou ainda não sincronizada).</td></tr>
        @endforelse
        </tbody>
      </table>
      </div>

    </div>

    <div class="abas-painel" role="tabpanel" data-painel="roteiro" hidden>
      <h3>Roteiro de operações</h3>
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

    </div>

    {{-- Ferramenta/molde (SH4): cavidades ativas editáveis pelos administradores --}}
    <div class="abas-painel" role="tabpanel" data-painel="ferramenta" hidden>
      <h3>Ferramenta / molde da OP</h3>
      <p class="suave">Ferramentas citadas no roteiro da OP (G2_FERRAM → SH4). As cavidades ativas são do portal (nunca acima das cavidades do
        Protheus) @if ($podeEditarCavidades)e podem ser alteradas aqui. @else; só os administradores alteram. @endif</p>
      @error('h4_qtdcavativas')<p class="aviso erro">{{ $message }}</p>@enderror
      @if ($roteiro['ferramentas_codigos'])
        <div class="ferr-cartoes">
        @foreach ($roteiro['ferramentas_codigos'] as $cod)
          @php $f = $roteiro['ferramentas'][$cod] ?? null; @endphp
          <article class="ferr-cartao">
            <header><code>{{ $cod }}</code> <strong>{{ $f?->h4_descri ?? 'não encontrada na SH4 (ou de outra unidade)' }}</strong>
              @if ($f && ! $f->disponivel)<span class="etiqueta situacao-sem_sinal">Indisponível</span>@endif</header>
            @if ($f)
              <dl class="op-ficha compacta">
                <div><dt>Cavidades (Protheus)</dt><dd>{{ $f->h4_qtdcav }}</dd></div>
                <div><dt>Quantidade</dt><dd>{{ Formato::quantidade($f->h4_quant) }}</dd></div>
                <div><dt>Vida útil</dt><dd>{{ $f->h4_vidauti > 0 ? Formato::quantidade($f->h4_vidauti) : '-' }}</dd></div>
                <div><dt>Operações</dt><dd>{{ $roteiro['operacoes']->filter(fn ($o) => rtrim($o->g2_ferram) === $cod)->map(fn ($o) => trim($o->g2_operac).' '.trim($o->g2_descri))->join(', ') ?: '-' }}</dd></div>
              </dl>
              @if ($podeEditarCavidades)
                <form method="post" action="{{ route('ferramentas.update', $f) }}" class="ferr-cavidades">
                  @csrf @method('put')
                  <input type="hidden" name="op" value="{{ $op->id }}">
                  <label>Cavidades ativas
                    <span class="ferr-campo"><input type="number" name="h4_qtdcavativas" min="0" max="{{ $f->h4_qtdcav }}" step="1" required
                      value="{{ old('h4_qtdcavativas', $f->h4_qtdcavativas) }}"><small>de {{ $f->h4_qtdcav }}</small></span></label>
                  <button class="botao" type="submit">Salvar</button>
                </form>
              @else
                <p class="ferr-cavidades-leitura">Cavidades ativas: <strong>{{ $f->h4_qtdcavativas }}</strong> de {{ $f->h4_qtdcav }}</p>
              @endif
              @if ($f->cavidadesAcima())<p class="texto-alerta">As cavidades ativas estão acima das do Protheus: ajuste.</p>@endif
            @endif
          </article>
        @endforeach
        </div>
      @else
        <p class="suave">O roteiro desta OP não cita ferramenta/molde.</p>
      @endif
    </div>
  </div>
</section>
@endsection
