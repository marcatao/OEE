{{--
  Cartão de uma máquina no panorama (apontamentos reais): situação atual, OP, produto, molde e cavidades, tempos do
  período, disponibilidade, performance pela meta (IoT) e molde indicado para conserto. Parâmetros: $maquina, $sit (Timeline::situacoes), $dados (PanoramaMaquinas, da máquina),
  $periodo e opcionais $link (URL; sem link = cartão fixo) e $ind (OEE das peças, quando houver).
--}}
@php
  $fuso = config('oee.fuso_exibicao');
  $estado = $sit['estado'] ?? 'sem';
  $seg = $dados['segundos'];
  $total = max(1, $dados['apontado']);
  $tag = ! empty($link) ? 'a' : 'div';
@endphp
<{{ $tag }} class="pm-cartao estado-{{ $estado }}" @if (! empty($link)) href="{{ $link }}" @endif>
  <header class="pm-topo">
    <div class="pm-nome"><strong>{{ $maquina->nome }}</strong>
      @if ($maquina->localizacao())<small>{{ $maquina->localizacao() }}</small>@endif</div>
    <span class="pm-situacao"><i class="tl-pulso" aria-hidden="true"></i>{{ $sit['rotulo'] ?? 'Sem informação' }}</span>
  </header>
  @if (! empty($sit['legenda']))
    <p class="pm-legenda"><i class="tl-cor" style="background: {{ $sit['legenda']->cor }}"></i>{{ $sit['legenda']->codigo }} · {{ $sit['legenda']->descricao }}
      @if ($sit['desde']) · desde {{ $sit['desde']->copy()->setTimezone($fuso)->format($sit['desde']->copy()->setTimezone($fuso)->isSameDay(now($fuso)) ? 'H:i' : 'd/m H:i') }}@endif</p>
  @endif

  <div class="pm-op">
    @if (! empty($sit['op']))
      {{-- Cartão que já é um link (lista do Dashboard por máquina): link dentro de link quebra o HTML e o navegador
           desmonta o cartão; ali a OP vai em texto (o link da OP fica na tela da máquina). --}}
      <span class="pm-op-numero">@if (! empty($link))OP {{ $sit['op']->numero }}@else @include('partials.link-op', ['op' => $sit['op'], 'texto' => 'OP '.$sit['op']->numero])@endif</span>
      <small>{{ $sit['produto'] ? $sit['produto']->codigo().' · '.$sit['produto']->descricao() : rtrim($sit['op']->c2_produto) }}</small>
      @forelse ($sit['moldes'] as $molde)
        <small class="pm-molde">Molde <b>{{ $molde['codigo'] }}</b>@if ($molde['ferramenta']) · {{ $molde['ferramenta']->h4_descri }} ·
          <b>{{ $molde['ferramenta']->h4_qtdcavativas }}</b>/{{ $molde['ferramenta']->h4_qtdcav }} cav. ativas @endif</small>
      @empty
        <small class="pm-molde suave">Sem molde no roteiro da OP</small>
      @endforelse
    @else
      <span class="suave">Sem OP</span>
    @endif
  </div>

  {{-- OEE (IoT) da máquina no período: D × P × Q (perdas apontadas), com o medidor --}}
  @php($oeeMaq = $dados['oeeIot'] ?? null)
  <div class="pm-oee {{ \App\Support\Formato::nivel($oeeMaq) }}"
       title="OEE (IoT) = disponibilidade × performance (limitada a 100%) × qualidade, pelos apontamentos reais, o IoT e as perdas apontadas{{ $oeeMaq === null ? '. Sem meta nas OPs do período: sem performance, sem OEE.' : '' }}">
    <div class="medidor mini" style="--pct: {{ \App\Support\Formato::pct100($oeeMaq) }}"><div><strong>{{ \App\Support\Formato::pct($oeeMaq, 0) }}</strong></div></div>
    <div><span>OEE</span>
      <small>D {{ \App\Support\Formato::pct($dados['disponibilidade'], 0) }} · P {{ ($dados['performance'] ?? null) === null ? '—' : \App\Support\Formato::pct($dados['performance'], 0) }} · Q {{ ($dados['qualidade'] ?? null) === null ? '—' : \App\Support\Formato::pct($dados['qualidade'], 0) }}</small>
      @if (($dados['pecasTodas'] ?? 0) > 0)<small>{{ \App\Support\Formato::inteiro($dados['pecasTodas']) }} pç · {{ \App\Support\Formato::quantidade($dados['perdas']) }} perdidas</small>@endif
    </div>
  </div>

  <div class="pm-resumo">
    <div class="{{ \App\Support\Formato::nivel($dados['disponibilidade']) }}"><span>Disponib.</span><strong class="texto-nivel">{{ \App\Support\Formato::pct($dados['disponibilidade'], 0) }}</strong></div>
    <div><span>Produzindo</span><strong>{{ \App\Support\Formato::minutos($seg['produzindo'] / 60) }}</strong></div>
    <div title="Só paradas não planejadas: parada planejada e fora de turno não contam"><span>Parada</span><strong>{{ \App\Support\Formato::minutos(($seg['parada'] ?? 0) / 60) }}</strong></div>
    <div class="{{ ($dados['performance'] ?? null) === null ? '' : \App\Support\Formato::nivel(min($dados['performance'], 1)) }}"
         title="Peças do IoT ÷ peças na meta (meta da OP, item MO da estrutura, × tempo produzindo){{ ($dados['pecasNaMeta'] ?? null) ? ': '.\App\Support\Formato::inteiro($dados['pecas']).' de '.\App\Support\Formato::inteiro($dados['pecasNaMeta']).' peças' : ': sem meta no período' }}">
      <span>Performance</span><strong class="texto-nivel">{{ ($dados['performance'] ?? null) === null ? '—' : \App\Support\Formato::pct($dados['performance'], 0) }}</strong></div>
  </div>
  @if (! empty($sit['moldeConserto']))
    <small class="pm-rodape texto-alerta">Molde {{ $sit['moldeConserto']['codigo'] }} com {{ $sit['moldeConserto']['ativas'] }}/{{ $sit['moldeConserto']['total'] }} cavidades abaixo da meta: indicar para conserto</small>
  @endif
  @if ($dados['apontado'] > 0)
    <div class="pm-barra" title="Tempo apontado no período por tipo">
      @foreach (['produzindo', 'parada', 'planejada', 'desligada'] as $tipo)
        @if (($seg[$tipo] ?? 0) > 0)<i class="pm-{{ $tipo }}" style="width: {{ round($seg[$tipo] / $total * 100, 2) }}%"></i>@endif
      @endforeach
    </div>
    @if ($dados['maiorParada'])
      <small class="pm-rodape">Maior parada: {{ $dados['maiorParada']['legenda']->codigo }} · {{ $dados['maiorParada']['legenda']->descricao }}
        ({{ \App\Support\Formato::minutos($dados['maiorParada']['segundos'] / 60) }})</small>
    @endif
  @else
    <small class="pm-rodape suave">Sem apontamentos em {{ mb_strtolower($periodo->rotulo()) }}</small>
  @endif
  @if ($dados['pendentesOp'] > 0)<small class="pm-rodape texto-alerta">{{ $dados['pendentesOp'] }} {{ $dados['pendentesOp'] === 1 ? 'apontamento' : 'apontamentos' }} sem OP</small>@endif
  @if (! empty($ind) && $ind->temDados())
    <small class="pm-rodape">OEE (peças) {{ \App\Support\Formato::pct($ind->oee(), 0) }} · D {{ \App\Support\Formato::pct($ind->disponibilidade(), 0) }} · P {{ \App\Support\Formato::pct($ind->performance(), 0) }} · Q {{ \App\Support\Formato::pct($ind->qualidade(), 0) }}</small>
  @endif
</{{ $tag }}>
