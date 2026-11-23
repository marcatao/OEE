{{-- Área de resultado da Produção por hora (trocada sem recarregar a página: ?parcial=1). --}}
@use('App\Support\Formato')
@php
  $fuso = config('oee.fuso_exibicao');
  $pcMin = fn ($v) => number_format((float) $v, 1, ',', '.');
@endphp
<div data-ao-vivo="{{ $geral['aoVivo'] ? '1' : '0' }}">
  <div class="pm-panorama">
    <div><span>Máquinas</span><strong>{{ $geral['maquinas'] }}</strong></div>
    <div class="estado-produzindo"><span>Produzindo agora</span><strong>{{ $geral['produzindo'] }}</strong></div>
    <div><span>Peças no dia</span><strong>{{ Formato::inteiro($geral['total']) }}</strong></div>
    <div><span>Pç/min agora (soma)</span><strong>{{ $pcMin($geral['pecasPorMinuto']) }}</strong></div>
    <div title="Soma das metas (pç/min) das máquinas cuja OP atual tem item MO na estrutura"><span>Meta pç/min (soma)</span><strong class="{{ $geral['metaPorMinuto'] === null ? 'suave' : '' }}">{{ $geral['metaPorMinuto'] === null ? 'Sem meta' : $pcMin($geral['metaPorMinuto']) }}</strong></div>
    @if ($geral['consertos'] > 0)
      <div class="estado-parada" title="Moldes com cavidades desativadas e produção abaixo da meta"><span>Moldes p/ conserto</span><strong>{{ $geral['consertos'] }}</strong></div>
    @endif
    <div><span>Atualizado</span><strong>{{ $geral['atualizadoEm']->format('H:i') }}</strong></div>
  </div>

  @if (! $maquinas)
    <p class="vazio" style="margin-top:1.25rem">Nenhuma máquina ativa com IoT nos filtros escolhidos.</p>
  @else
    @if (count($maquinas) > 1 && $geral['total'] > 0)
      <section class="ph-geral">
        <header><h2>Todas as máquinas</h2><small class="suave">peças por hora do relógio, somando os turnos de cada máquina</small></header>
        @include('dashboard.producao-hora._grafico', ['horas' => $geral['horas'], 'altura' => 170])
      </section>
    @endif

    <div class="ph-lista">
      @foreach ($maquinas as $d)
        @include('dashboard.producao-hora._maquina', ['d' => $d])
      @endforeach
    </div>
  @endif
</div>
