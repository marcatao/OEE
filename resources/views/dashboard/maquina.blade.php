@extends('layouts.portal')

@section('titulo', $maquina->nome)

@use('App\Support\Formato')

@section('conteudo')
@php($oee = $ind->oee())
<section class="cartao">
  <p class="trilha">Produção › <a href="{{ route('dashboard.maquinas', ['periodo' => $periodo->chave]) }}">Dashboard por máquina</a></p>
  <div class="titulo-com-acao">
    <div>
      <h1>{{ $maquina->nome }}
        @if (auth()->user()->ehAdmin())
          <a class="dm-engrenagem" href="{{ route('admin.maquinas.edit', $maquina) }}" title="Editar o cadastro da máquina" aria-label="Editar o cadastro da máquina">@include('layouts.partials.icone', ['nome' => 'engrenagem'])</a>
        @endif
      </h1>
      <p class="suave" style="margin:0">{{ $maquina->codigo }}@if ($maquina->localizacao()) · {{ $maquina->localizacao() }}@endif
        @if ($maquina->fabricante || $maquina->modelo) · {{ trim($maquina->fabricante.' '.$maquina->modelo) }}@endif</p>
    </div>
    <div class="filtros" style="margin:0">
      <form method="get" action="" onsubmit="return false" class="filtros" style="margin:0">
        <label>Máquina
          <select onchange="location.href=this.value">
            @foreach ($maquinas as $m)
              <option value="{{ route('dashboard.maquina', ['maquina' => $m, 'periodo' => $periodo->chave]) }}" @selected($m->id === $maquina->id)>{{ $m->nome }}</option>
            @endforeach
          </select>
        </label>
      </form>
      @include('dashboard._periodo', ['acao' => route('dashboard.maquina', $maquina)])
    </div>
  </div>

  @if ($paradaAberta)
    <p class="aviso alerta" role="status">@include('layouts.partials.icone', ['nome' => 'alerta'])
      <strong>Máquina parada</strong> desde @dataHora($paradaAberta->inicio) — {{ $paradaAberta->motivo?->descricao ?? 'sem justificativa' }}.</p>
  @endif
  @if ($ind->performanceAcimaDoIdeal())
    <p class="aviso alerta">A produção passou do ciclo ideal cadastrado (performance acima de 100%). Revise o ciclo ideal da máquina.</p>
  @endif
</section>

{{-- Panorama pelos apontamentos reais (Timeline): agora, OP, molde, tempos e as legendas do período --}}
@php($dadosMaq = $panorama['maquinas'][$maquina->id])
<div class="pm-detalhe">
  @include('dashboard._cartao-maquina', ['sit' => $panorama['situacoes'][$maquina->id] ?? [], 'dados' => $dadosMaq, 'ind' => null, 'link' => null])
  <section class="cartao">
    <h3>Tempo por legenda — {{ $periodo->rotulo() }}</h3>
    @php($maiorLeg = max(array_column($panorama['geral']['totais'], 'segundos') ?: [0]))
    @forelse ($panorama['geral']['totais'] as $t)
      <div style="margin-top:.7rem">
        <div class="barra-rotulo"><span><i class="tl-cor" style="background: {{ $t['legenda']->cor }}"></i> {{ $t['legenda']->codigo }} · {{ $t['legenda']->descricao }}
          <small class="suave">({{ \App\Models\Legenda::TIPOS[$t['legenda']->tipo] ?? $t['legenda']->tipo }})</small></span>
          <strong>{{ Formato::minutos($t['segundos'] / 60) }} · {{ Formato::pct($t['pct']) }}</strong></div>
        <div class="barra"><i style="width: {{ $maiorLeg > 0 ? round($t['segundos'] / $maiorLeg * 100, 1) : 0 }}%; background: {{ $t['legenda']->cor }}"></i></div>
      </div>
    @empty
      <p class="suave">Nenhum apontamento real desta máquina em {{ mb_strtolower($periodo->rotulo()) }}.</p>
    @endforelse
  </section>
</div>

{{-- Timeline da máquina no período do filtro (todas as legendas, como a tela Timeline) --}}
@php($fusoTl = config('oee.fuso_exibicao'))
@php($podeApontarTl = auth()->user()->pode('apontamentos'))
<section class="cartao" style="margin-top:1.25rem">
  <h3 style="margin-top:0">Timeline — {{ $periodo->rotulo() }}</h3>
  <div class="tl-grade dm-timeline">
    <div class="tl-faixa">
      @forelse ($timelineMaquina['blocos'] as $b)
        @php($legenda = $b['legenda'])
        @php($dica = $legenda->codigo.' · '.$legenda->descricao.' · '
            .\Carbon\Carbon::createFromTimestamp($b['inicio'], $fusoTl)->format('d/m H:i').'–'
            .($b['aberto'] && $b['fim'] >= now()->getTimestamp() - 60 ? 'agora' : \Carbon\Carbon::createFromTimestamp($b['fim'], $fusoTl)->format('H:i'))
            .' ('.Formato::minutos(($b['fim'] - $b['inicio']) / 60).')'
            .($b['apontamento']->ordemProducao ? ' · OP '.$b['apontamento']->ordemProducao->numero : ''))
        @php($estilo = 'left: '.$b['esquerda'].'%; width: '.$b['largura'].'%; background-color: '.$legenda->cor)
        @if ($podeApontarTl)
          <a class="tl-bloco {{ $legenda->classeTexto() }} {{ $b['aberto'] ? 'aberto' : '' }}" style="{{ $estilo }}" title="{{ $dica }}"
             href="{{ route('apontamentos.edit', ['apontamento' => $b['apontamento']]) }}"><span><b>{{ $b['apontamento']->rotuloBarra() }}</b><em class="tl-desc"> · {{ $legenda->descricao }}</em></span></a>
        @else
          <div class="tl-bloco {{ $legenda->classeTexto() }} {{ $b['aberto'] ? 'aberto' : '' }}" style="{{ $estilo }}" title="{{ $dica }}"><span><b>{{ $b['apontamento']->rotuloBarra() }}</b><em class="tl-desc"> · {{ $legenda->descricao }}</em></span></div>
        @endif
      @empty
        <span class="dm-timeline-vazia suave">Nenhum apontamento real em {{ mb_strtolower($periodo->rotulo()) }}.</span>
      @endforelse
      @if ($timelineMaquina['agora'] !== null)<i class="tl-agora" style="left: {{ $timelineMaquina['agora'] }}%" title="Agora"></i>@endif
    </div>
    <div class="tl-eixo">
      @foreach ($timelineMaquina['marcas'] as $marca)
        <span @class(['tl-marca', 'virada' => $marca['virada']]) style="left: {{ $marca['esquerda'] }}%">{{ $marca['rotulo'] }}</span>
      @endforeach
    </div>
  </div>
</section>

{{-- Produção por hora desta máquina (o mesmo cartão de Dashboards › Produção por hora) --}}
@if ($producaoHora)
  <section style="margin-top:1.25rem">
    <h3 style="margin:0 0 .5rem">Produção por hora — {{ $diaProducao->isSameDay(now(config('oee.fuso_exibicao'))) ? 'hoje' : $diaProducao->format('d/m/Y') }}</h3>
    @include('dashboard.producao-hora._maquina', ['d' => $producaoHora])
  </section>
@endif

<section class="cartao" style="margin-top:1.25rem">
  <h3>Apontamentos reais — {{ $periodo->rotulo() }}</h3>
  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Dia</th><th>Legenda</th><th>Início</th><th>Fim</th><th class="num">Duração</th><th>OP</th><th>Referência</th>
      @if (auth()->user()->pode('apontamentos'))<th></th>@endif</tr></thead>
    <tbody>
    @forelse ($apontamentosReais as $a)
      @php($ini = $a->inicio->copy()->setTimezone(config('oee.fuso_exibicao')))
      <tr>
        <td>{{ $ini->format('d/m/Y') }}</td>
        <td>@if ($a->legenda)<span class="tl-chip"><i class="tl-cor" style="background: {{ $a->legenda->cor }}"></i>{{ $a->legenda->codigo }}</span> <small>{{ $a->legenda->descricao }}</small>@endif</td>
        <td>{{ $ini->format('H:i:s') }}</td>
        <td>@if ($a->fim){{ $a->fim->copy()->setTimezone(config('oee.fuso_exibicao'))->format('H:i:s') }}@else<span class="etiqueta situacao-online">em aberto</span>@endif</td>
        <td class="num">{{ Formato::minutos($a->duracaoMin()) }}</td>
        <td>@if ($a->ordemProducao)<code>@include('partials.link-op', ['op' => $a->ordemProducao])</code>@elseif ($a->legenda?->exige_op)<small class="texto-alerta">sem OP</small>@else - @endif</td>
        <td>{{ $a->referencia ?: '-' }}</td>
        @if (auth()->user()->pode('apontamentos'))<td><a href="{{ route('apontamentos.edit', $a) }}">Editar</a></td>@endif
      </tr>
    @empty
      <tr><td colspan="8" class="suave">Nenhum apontamento real nesse período.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
</section>

@if (! $ind->temDados())
  <section class="cartao vazio" style="margin-top:1.25rem">
    @include('layouts.partials.icone', ['nome' => 'dashboard'])
    <p>OEE completo (performance e qualidade): ainda sem contagem de peças desta máquina em <strong>{{ mb_strtolower($periodo->rotulo()) }}</strong>.</p>
    <p><small>A contagem de peças chegará pelos dispositivos IoT ligados à máquina. A disponibilidade acima vem dos apontamentos reais.</small></p>
  </section>
@else
  <div class="grade duas">
    <section class="cartao {{ Formato::nivel($oee) }}">
      <h3>OEE — {{ $periodo->rotulo() }}</h3>
      <div class="painel-oee">
        <div class="medidor" style="--pct: {{ Formato::pct100($oee) }}"><div><strong>{{ Formato::pct($oee) }}</strong><span>OEE</span></div></div>
        <div class="barras">
          @foreach (['Disponibilidade' => $ind->disponibilidade(), 'Performance' => $ind->performance(), 'Qualidade' => $ind->qualidade()] as $rotulo => $valor)
            <div class="{{ Formato::nivel($valor) }}">
              <div class="barra-rotulo"><span>{{ $rotulo }}</span><strong>{{ Formato::pct($valor) }}</strong></div>
              <div class="barra"><i style="width: {{ Formato::pct100($valor) }}%"></i></div>
            </div>
          @endforeach
        </div>
      </div>
    </section>

    <section class="cartao">
      <h3>Tempos e produção</h3>
      <div class="numeros">
        <div><span>Tempo planejado</span><strong>{{ Formato::minutos($ind->tempoPlanejadoMin()) }}</strong></div>
        <div><span>Tempo operando</span><strong>{{ Formato::minutos($ind->tempoOperandoMin()) }}</strong></div>
        <div><span>Paradas não planejadas</span><strong>{{ Formato::minutos($ind->paradasNaoPlanejadasMin) }}</strong></div>
        <div><span>Paradas planejadas</span><strong>{{ Formato::minutos($ind->paradasPlanejadasMin) }}</strong></div>
        <div><span>Total produzido</span><strong>{{ Formato::inteiro($ind->totalProduzido) }}</strong></div>
        <div><span>Peças boas</span><strong>{{ Formato::inteiro($ind->pecasBoas) }}</strong></div>
        <div><span>Refugo</span><strong>{{ Formato::inteiro($ind->refugo()) }}</strong></div>
        <div><span>Apontamentos</span><strong>{{ Formato::inteiro($ind->apontamentos) }}</strong></div>
      </div>
    </section>
  </div>

  <div class="grade duas" style="margin-top:1.25rem">
    <section class="cartao">
      <h3>Paradas por motivo</h3>
      @php($maior = max(array_column($ind->pareto, 'minutos') ?: [0]))
      @forelse ($ind->pareto as $linha)
        <div style="margin-top:.75rem">
          <div class="barra-rotulo"><span>{{ $linha['motivo'] }} @if ($linha['planejada'])<small class="suave">(planejada)</small>@endif</span>
            <strong>{{ Formato::minutos($linha['minutos']) }} · {{ $linha['ocorrencias'] }}×</strong></div>
          <div class="barra {{ $linha['planejada'] ? 'nivel-sem' : 'nivel-ruim' }}"><i style="width: {{ $maior > 0 ? round($linha['minutos'] / $maior * 100, 1) : 0 }}%"></i></div>
        </div>
      @empty
        <p class="suave">Nenhuma parada no período.</p>
      @endforelse
    </section>

    <section class="cartao">
      <h3>Dispositivos IoT</h3>
      @forelse ($maquina->dispositivos as $d)
        <p style="display:flex;justify-content:space-between;gap:1rem;margin:.6rem 0">
          <span>{{ $d->nome }} <small class="suave">{{ $d->rotuloProtocolo() }}</small></span>
          <span class="etiqueta situacao-{{ $d->situacao() }}"><i class="ponto"></i>{{ $d->rotuloSituacao() }}</span>
        </p>
      @empty
        <p class="suave">Nenhum dispositivo ligado a esta máquina.</p>
      @endforelse
    </section>
  </div>

  <section class="cartao" style="margin-top:1.25rem">
    <h3>Últimos apontamentos</h3>
    <div class="rolagem">
    <table class="tabela">
      <thead><tr><th>Início</th><th>Fim</th><th class="num">Ciclo ideal (s)</th><th class="num">Produzido</th><th class="num">Boas</th><th class="num">Refugo</th></tr></thead>
      <tbody>
      @foreach ($apontamentos as $a)
        <tr>
          <td>@dataHora($a->inicio)</td><td>@dataHora($a->fim)</td>
          <td class="num">{{ number_format($a->ciclo_ideal_s, 1, ',', '.') }}</td>
          <td class="num">{{ Formato::inteiro($a->total_produzido) }}</td>
          <td class="num">{{ Formato::inteiro($a->pecas_boas) }}</td>
          <td class="num">{{ Formato::inteiro($a->total_produzido - $a->pecas_boas) }}</td>
        </tr>
      @endforeach
      </tbody>
    </table>
    </div>
  </section>
@endif
@endsection
