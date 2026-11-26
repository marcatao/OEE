{{-- Ciclo a ciclo da máquina num dia, lido da IOT001 (App\Services\CiclosIot; pedido do usuário em 26/11/2026).
     Variáveis: $ciclos (CiclosIot::doDia), $diasCiclos (dias do período), $periodo, $maquina.
     O desenho (canvas) fica em public/js/componentes.js ([data-grafico-ciclos]). --}}
@use('App\Support\Segundos')
@php($r = $ciclos['resumo'])
@php($lim = $ciclos['limites'])
<section class="cartao ciclos-iot" id="ciclos" style="margin-top:1.25rem">
  <div class="cartao-titulo">
    <h3 style="margin:0">Ciclos da máquina — {{ $ciclos['dia']->format('d/m/Y') }}</h3>
    <form method="get" action="{{ route('dashboard.maquina', $maquina) }}#ciclos" class="ciclos-filtro">
      <input type="hidden" name="periodo" value="{{ $periodo->chave }}">
      <label>Dia
        <select name="ciclos_dia" onchange="this.form.submit()">
          @foreach ($diasCiclos as $d)
            <option value="{{ $d->format('Y-m-d') }}" @selected($d->equalTo($ciclos['dia']))>{{ ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'][$d->dayOfWeek] }} {{ $d->format('d/m/Y') }}</option>
          @endforeach
        </select>
      </label>
      <noscript><button class="botao secundario pequeno" type="submit">Ver</button></noscript>
    </form>
  </div>
  <p class="suave ciclos-fonte">
    @if ($ciclos['origem'])
      Leituras de <code>{{ $ciclos['tabela'] }}</code>, dispositivo <strong>{{ $ciclos['origem']['dispositivo']->codigo }}</strong>,
      canal <code>{{ $ciclos['origem']['canal'] }}</code>. Cada barra é uma mudança do contador: a altura é o ciclo por peça
      (intervalo desde a mudança anterior ÷ peças que o contador subiu).
    @endif
  </p>

  @if ($ciclos['erro'])
    <p class="aviso alerta" role="status">{{ $ciclos['erro'] }}</p>
  @elseif (! $ciclos['ciclos'])
    <p class="suave">Nenhuma mudança do contador em {{ $ciclos['dia']->format('d/m/Y') }}
      ({{ \App\Support\Formato::inteiro($r['leituras']) }} leituras do aparelho no dia).</p>
  @else
    <div class="numeros ciclos-numeros">
      <div><span>Ciclo médio</span><strong>{{ $r['ciclo_medio'] === null ? '—' : Segundos::ciclo($r['ciclo_medio']) }}</strong></div>
      <div><span>Ciclo mediano</span><strong>{{ $r['ciclo_mediano'] === null ? '—' : Segundos::ciclo($r['ciclo_mediano']) }}</strong></div>
      <div><span>Menor / maior ciclo</span><strong>{{ $r['ciclo_minimo'] === null ? '—' : str_replace(' s', '', Segundos::ciclo($r['ciclo_minimo'])).' / '.Segundos::ciclo($r['ciclo_maximo']) }}</strong></div>
      <div><span>Peças (mudanças)</span><strong>{{ \App\Support\Formato::inteiro($r['pecas']) }} <small class="suave">({{ \App\Support\Formato::inteiro($r['mudancas']) }})</small></strong></div>
      <div class="ciclos-n-micro"><span>Microparadas</span><strong>{{ \App\Support\Formato::inteiro($r['micro']) }}</strong></div>
      <div class="ciclos-n-parada"><span>Paradas grandes</span><strong>{{ \App\Support\Formato::inteiro($r['paradas']) }}@if ($r['paradas']) <small>({{ \App\Support\Formato::minutos($r['tempo_paradas'] / 60) }})</small>@endif</strong></div>
    </div>

    <div class="ciclos-legenda">
      <span><i class="ciclos-cor normal"></i> Ciclo normal (intervalo &lt; {{ Segundos::limite($lim['micro']) }} s)</span>
      <span><i class="ciclos-cor micro"></i> Microparada (de {{ Segundos::limite($lim['micro']) }} s até {{ Segundos::limite($lim['macro']) }} s)</span>
      <span><i class="ciclos-cor parada"></i> Parada grande (≥ {{ Segundos::limite($lim['macro']) }} s{{ $lim['macro_padrao'] ? ', padrão: cadastro sem macro parada' : '' }}) — barra cortada, marcada com ▲</span>
      <label class="ciclos-hora">Horas
        <select data-ciclos-hora aria-label="Mostrar só uma hora do dia">
          <option value="">Dia inteiro</option>
          @foreach (range(0, 23) as $h)<option value="{{ $h }}">{{ sprintf('%02d:00 – %02d:59', $h, $h) }}</option>@endforeach
        </select>
      </label>
      <button type="button" class="botao secundario pequeno" data-ciclos-csv>Baixar CSV</button>
    </div>

    <div class="ciclos-grafico" data-grafico-ciclos data-fuso="{{ \App\Services\HorarioApontamento::fuso() }}"
         data-arquivo="ciclos-{{ $maquina->codigo }}-{{ $ciclos['dia']->format('Y-m-d') }}.csv" data-canal="{{ $ciclos['origem']['canal'] }}">
      <canvas role="img" aria-label="Gráfico de barras com {{ $r['mudancas'] }} ciclos em {{ $ciclos['dia']->format('d/m/Y') }}: ciclo médio {{ $r['ciclo_medio'] === null ? 'sem dado' : Segundos::ciclo($r['ciclo_medio']) }}, {{ $r['paradas'] }} paradas grandes."></canvas>
      <div class="ciclos-dica" hidden></div>
      <script type="application/json">@json(\App\Services\CiclosIot::paraGrafico($ciclos['ciclos']))</script>
    </div>

    @if ($r['paradas'])
      <details class="ciclos-paradas">
        <summary>Paradas grandes do dia ({{ $r['paradas'] }}; maior: {{ \App\Support\Formato::minutos($r['maior_parada'] / 60) }})</summary>
        <div class="rolagem" tabindex="0" role="region" aria-label="Paradas grandes do dia">
          <table class="tabela">
            <thead><tr><th>Voltou a produzir às</th><th class="num">Parada (intervalo)</th><th class="num">Contador</th><th class="num">Peças</th></tr></thead>
            <tbody>
              @foreach (array_filter($ciclos['ciclos'], fn ($c) => $c['nivel'] === 'parada') as $c)
                <tr><td>{{ \Carbon\CarbonImmutable::createFromTimestamp($c['ts'], \App\Services\HorarioApontamento::fuso())->format('H:i:s') }}</td>
                  <td class="num">{{ \App\Support\Formato::minutos($c['intervalo'] / 60) }}</td>
                  <td class="num">{{ \App\Support\Formato::quantidade($c['valor']) }}</td><td class="num">{{ $c['pecas'] }}</td></tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </details>
    @endif
  @endif
</section>
