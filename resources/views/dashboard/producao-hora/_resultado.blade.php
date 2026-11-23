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
        @php
          $m = $d['maquina']; $sit = $d['situacao']; $op = $sit['op'] ?? null; $estado = $sit['estado'] ?? 'sem';
          $produto = $d['produto'];
        @endphp
        <article class="ph-maquina estado-{{ $estado }}">
          <header class="ph-topo">
            <div class="ph-nome">
              <strong>{{ $m->nome }}</strong>
              <small>{{ $m->codigo }}@if ($m->localizacao()) · {{ $m->localizacao() }}@endif</small>
            </div>
            <span class="ph-turno suave">@include('layouts.partials.icone', ['nome' => 'relogio']) {{ $d['rotulo'] }}</span>
            <span class="pm-situacao"><i class="tl-pulso" aria-hidden="true"></i>{{ $sit['rotulo'] ?? 'Sem informação' }}</span>
          </header>

          <div class="ph-corpo">
            <div class="ph-info">
              <div class="ph-op">
                @if ($op)
                  <span class="ph-op-rotulo">OP atual</span>
                  <span class="ph-op-numero">@include('partials.link-op', ['op' => $op])</span>
                  <span class="ph-produto"><b>{{ $produto ? $produto->codigo() : rtrim($op->c2_produto) }}</b>@if ($produto) · {{ $produto->descricao() }}@endif</span>
                  @foreach ($sit['moldes'] ?? [] as $molde)
                    <small class="suave">Molde {{ $molde['codigo'] }}@if ($molde['ferramenta']) · {{ $molde['ferramenta']->h4_qtdcavativas }}/{{ $molde['ferramenta']->h4_qtdcav }} cav. ativas @endif</small>
                  @endforeach
                @else
                  <span class="ph-op-rotulo">OP atual</span>
                  <span class="suave">Sem OP na máquina</span>
                @endif
              </div>

              <div class="ph-agora">
                <strong>{{ $pcMin($d['pecasPorMinuto']) }}</strong>
                <span>pç/min agora{{ $d['metaPorMinuto'] !== null ? ' · meta '.$pcMin($d['metaPorMinuto']) : ' · sem meta' }}</span>
              </div>
              @if ($d['moldeConserto'])
                @php($mc = $d['moldeConserto'])
                <p class="aviso alerta" style="margin:0;padding:.4rem .6rem;font-size:.85rem">Molde <b>{{ $mc['codigo'] }}</b> com {{ $mc['ativas'] }}/{{ $mc['total'] }} cavidades ativas e abaixo da meta: indicar para conserto.</p>
              @endif

              <dl class="ph-numeros">
                <div><dt>Peças no turno</dt><dd>{{ Formato::inteiro($d['total']) }}</dd></div>
                <div><dt>Meta do turno</dt><dd class="{{ $d['metaTurno'] === null ? 'suave' : '' }}">{{ $d['metaTurno'] === null ? 'Sem meta' : Formato::inteiro($d['metaTurno']) }}</dd></div>
                <div><dt>Média por hora</dt><dd>{{ $d['mediaHora'] === null ? '—' : Formato::inteiro($d['mediaHora']) }}</dd></div>
                <div><dt>Melhor hora</dt><dd>{{ Formato::inteiro($d['melhorHora']) }}</dd></div>
                <div><dt>Ciclo médio</dt><dd>{{ \App\Support\Segundos::ciclo($d['cicloMedio']) }}</dd></div>
                <div><dt>Cavidades</dt><dd>{{ $d['cavidades'] }}</dd></div>
              </dl>
            </div>

            <div class="ph-grafico">
              @if ($d['horas'])
                @include('dashboard.producao-hora._grafico', ['horas' => $d['horas']])
                <div class="ph-tabela-rolagem">
                  <table class="ph-tabela">
                    <tr><th scope="row">Hora</th>@foreach ($d['horas'] as $h)<td class="{{ $h['atual'] ? 'atual' : '' }}">{{ substr($h['hora'], 0, 2) }}h</td>@endforeach<th scope="col">Total</th></tr>
                    <tr><th scope="row">Peças</th>@foreach ($d['horas'] as $h)<td class="{{ $h['atual'] ? 'atual' : '' }} {{ $h['futura'] ? 'suave' : '' }}">{{ $h['futura'] ? '' : Formato::inteiro($h['pecas']) }}</td>@endforeach<th>{{ Formato::inteiro($d['total']) }}</th></tr>
                    <tr><th scope="row">Meta</th>@foreach ($d['horas'] as $h)<td class="suave">{{ $h['meta'] === null ? '—' : Formato::inteiro($h['meta']) }}</td>@endforeach<th class="suave">{{ $d['metaTurno'] === null ? '—' : Formato::inteiro($d['metaTurno']) }}</th></tr>
                    @if ($d['metaTurno'] !== null)
                      <tr><th scope="row">% meta</th>@foreach ($d['horas'] as $h)<td>{{ $h['futura'] || ! $h['meta'] ? '' : Formato::pct($h['pecas'] / $h['meta'], 0) }}</td>@endforeach<th>{{ $d['metaTurno'] ? Formato::pct($d['total'] / $d['metaTurno'], 0) : '' }}</th></tr>
                    @endif
                  </table>
                </div>
              @else
                <p class="suave">Sem horas de turno neste dia.</p>
              @endif
            </div>
          </div>

          <footer class="ph-ops">
            <span class="suave">OPs no turno:</span>
            @forelse ($d['ops'] as $o)
              <span class="ph-op-chip" title="{{ $o['produto']?->descricao() }}">
                @if ($o['op'])<b>@include('partials.link-op', ['op' => $o['op'], 'texto' => 'OP '.$o['op']->numero])</b> · {{ $o['produto'] ? $o['produto']->codigo() : rtrim($o['op']->c2_produto) }}@else<b>Sem OP</b>@endif
                <strong>{{ Formato::inteiro($o['pecas']) }} pç</strong>
              </span>
            @empty
              <span class="suave">nenhuma peça contada pelo IoT no turno.</span>
            @endforelse
          </footer>
        </article>
      @endforeach
    </div>
  @endif
</div>
