@extends('layouts.portal')

@section('titulo', 'Dashboard por máquina')

@use('App\Support\Formato')

@section('conteudo')
@php
  $c = $panorama['contagem'];
  $g = $panorama['geral'];
  $geral = \App\Services\IndicadoresPeriodo::somar($indicadores);
@endphp
<section class="cartao">
  <p class="trilha">Produção</p>
  <div class="titulo-com-acao">
    <div>
      <h1>Dashboard por máquina</h1>
      <p class="suave" style="margin:0">Panorama das máquinas ativas com IoT ativo, pelos apontamentos reais (os da Timeline) em:
        <strong>{{ $periodo->rotulo() }}</strong>. Clique numa máquina para ver os detalhes.</p>
    </div>
    @include('dashboard._periodo', ['acao' => route('dashboard.maquinas')])
  </div>

  <div class="pm-panorama">
    <div><span>Máquinas</span><strong>{{ $maquinas->count() }}</strong></div>
    <div class="estado-produzindo"><span>Produzindo agora</span><strong>{{ $c['produzindo'] }}</strong></div>
    <div class="estado-parada"><span>Paradas agora</span><strong>{{ $c['parada'] }}</strong></div>
    <div class="estado-planejada"><span>Parada planejada</span><strong>{{ $c['planejada'] }}</strong></div>
    <div class="estado-desligada"><span>Fora de turno</span><strong>{{ $c['desligada'] }}</strong></div>
    <div><span>Sem informação</span><strong>{{ $c['sem'] }}</strong></div>
    <div class="{{ Formato::nivel($g['disponibilidade']) }}"><span>Disponibilidade</span><strong class="texto-nivel">{{ Formato::pct($g['disponibilidade']) }}</strong></div>
    <div><span>Tempo produzindo</span><strong>{{ Formato::minutos(($g['segundos']['produzindo'] ?? 0) / 60) }}</strong></div>
    <div title="Só paradas não planejadas: parada planejada e fora de turno não contam"><span>Tempo parado</span><strong>{{ Formato::minutos(($g['segundos']['parada'] ?? 0) / 60) }}</strong></div>
  </div>

  @if ($g['totais'])
    <div class="tl-resumo" style="margin-top:.9rem">
      <span class="suave">Por legenda:</span>
      @foreach ($g['totais'] as $t)
        <span class="tl-chip" title="{{ $t['legenda']->descricao }}"><i class="tl-cor" style="background: {{ $t['legenda']->cor }}"></i>{{ $t['legenda']->codigo }}
          <strong>{{ Formato::pct($t['pct']) }}</strong><small class="suave">{{ Formato::minutos($t['segundos'] / 60) }}</small></span>
      @endforeach
    </div>
  @endif

  @if ($geral->temDados())
    <p class="suave" style="margin:.9rem 0 .4rem">OEE pela contagem de peças:</p>
    <div class="numeros">
      <div class="{{ Formato::nivel($geral->oee()) }}"><span>OEE geral</span><strong class="texto-nivel">{{ Formato::pct($geral->oee()) }}</strong></div>
      <div><span>Performance</span><strong>{{ Formato::pct($geral->performance()) }}</strong></div>
      <div><span>Qualidade</span><strong>{{ Formato::pct($geral->qualidade()) }}</strong></div>
      <div><span>Peças boas</span><strong>{{ Formato::inteiro($geral->pecasBoas) }}</strong></div>
    </div>
  @endif
</section>

@if ($maquinas->isEmpty())
  <section class="cartao vazio">
    @include('layouts.partials.icone', ['nome' => 'maquina'])
    <p>Nenhuma máquina ativa com IoT ativo (canal com dispositivo ativo).</p>
    @if (auth()->user()->ehAdmin())
      <a class="botao" href="{{ route('admin.maquinas.index') }}">@include('layouts.partials.icone', ['nome' => 'maquina']) Cadastro de máquinas</a>
    @endif
  </section>
@else
  <div class="pm-grade">
    @foreach ($maquinas as $maquina)
      @include('dashboard._cartao-maquina', [
        'sit' => $panorama['situacoes'][$maquina->id] ?? [],
        'dados' => $panorama['maquinas'][$maquina->id],
        'ind' => $indicadores[$maquina->id] ?? null,
        'link' => route('dashboard.maquina', ['maquina' => $maquina, 'periodo' => $periodo->chave]),
      ])
    @endforeach
  </div>
@endif
@endsection
