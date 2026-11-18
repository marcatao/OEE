{{-- Painel de OS: tempos de atendimento e reparo × meta da área executante. Variáveis: $resumoTempos, $temposPorArea,
     $temposPorMes (só com mais de um mês no período), $nomeArea, $rotuloPeriodo, $tempo (minutos => texto). --}}
@php($pct = fn ($dentro, $com) => $com > 0 ? round($dentro / $com * 100) : null)
@php($situacao = fn ($valor, $meta) => $valor === null || $meta === null ? null : ($valor <= $meta ? 'dentro' : 'fora'))
<section class="cartao" id="tempos">
  <h2>Tempos de atendimento e reparo <small class="suave">{{ $nomeArea }} · {{ $rotuloPeriodo }}</small></h2>
  <p class="suave">OS concluídas no período. <strong>Atendimento</strong> = da abertura do chamado ao 1º apontamento de horas;
    <strong>reparo</strong> = do 1º apontamento à conclusão. A meta é a da área executante (Cadastros › Áreas executantes).</p>

  <div class="tempos-resumo">
    @foreach (['atendimento' => 'Atendimento', 'reparo' => 'Reparo'] as $chave => $titulo)
      @php($valor = $resumoTempos[$chave])
      @php($meta = $resumoTempos['meta_'.$chave])
      @php($sit = $situacao($valor, $meta))
      @php($p = $pct($resumoTempos['dentro_'.$chave], $resumoTempos['com_meta_'.$chave]))
      <div class="tempo-cartao tempo-{{ $chave }}">
        <span class="tempo-titulo"><i aria-hidden="true"></i>{{ $titulo }} médio</span>
        <strong>{{ $tempo($valor) }}</strong>
        <small>meta {{ $meta !== null ? $tempo($meta) : 'não definida' }}
          @if ($sit)<span class="tempo-sit tempo-sit-{{ $sit }}">{{ $sit === 'dentro' ? 'dentro da meta' : 'acima da meta' }}</span>@endif</small>
        @if ($p !== null)<small>{{ $p }}% das OS dentro da meta ({{ $resumoTempos['dentro_'.$chave] }} de {{ $resumoTempos['com_meta_'.$chave] }})</small>@endif
      </div>
    @endforeach
    <div class="tempo-cartao"><span class="tempo-titulo">OS concluídas</span><strong>{{ $resumoTempos['os'] }}</strong><small>com apontamento de horas</small></div>
  </div>

  <h3>Por área executante acionada</h3>
  <div class="rolagem">
  <table class="tabela tempos-areas">
    <thead><tr><th>Área executante</th><th class="num">OS</th>
      <th class="num">Atendimento</th><th class="num">Meta</th><th class="num">Dentro</th>
      <th class="num">Reparo</th><th class="num">Meta</th><th class="num">Dentro</th></tr></thead>
    <tbody>
    @forelse ($temposPorArea as $l)
      <tr>
        <td>{{ $l['area']?->nome ?? '—' }}</td>
        <td class="num">{{ $l['os'] }}</td>
        @foreach (['atendimento', 'reparo'] as $chave)
          @php($sit = $situacao($l[$chave], $l['meta_'.$chave]))
          @php($p = $pct($l['dentro_'.$chave], $l['com_meta_'.$chave]))
          <td class="num"><span @class(['tempo-sit-'.$sit => $sit])>{{ $tempo($l[$chave]) }}</span></td>
          <td class="num suave">{{ $l['meta_'.$chave] !== null ? $tempo($l['meta_'.$chave]) : '—' }}</td>
          <td class="num">{{ $p !== null ? $p.'%' : '—' }}</td>
        @endforeach
      </tr>
    @empty
      <tr><td colspan="8" class="suave">Nenhuma OS concluída com apontamento de horas no período.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
</section>

@if (! empty($temposPorMes))
  @php($maximo = max(1, collect($temposPorMes)->flatMap(fn ($m) => [$m['atendimento'], $m['reparo'], $m['meta_atendimento'], $m['meta_reparo']])->filter()->max() ?? 1))
  {{-- Escala em horas "redondas": 4 divisões. --}}
  @php($passoH = max(1, (int) ceil($maximo / 60 / 4)))
  @php($topo = $passoH * 4 * 60)
  @php($n = count($temposPorMes))
  @php($larg = 640) @php($alt = 240) @php($esq = 44) @php($base = 200) @php($util = $larg - $esq - 8)
  @php($grupo = $util / $n) @php($barra = min(28, max(8, ($grupo - 14) / 2)))
  @php($y = fn ($min) => $base - ($min / $topo) * ($base - 12))
  <section class="cartao" id="tempos-mes">
    <h2>Tempos mês a mês × meta <small class="suave">{{ $nomeArea }}</small></h2>
    <div class="grafico-legenda" aria-hidden="true">
      <span><i class="leg-barra leg-atendimento"></i>Atendimento médio</span>
      <span><i class="leg-barra leg-reparo"></i>Reparo médio</span>
      <span><i class="leg-meta"></i>Meta</span>
    </div>
    <svg class="grafico-tempos" viewBox="0 0 {{ $larg }} {{ $alt }}" role="img" aria-label="Tempo médio de atendimento e de reparo por mês, comparado à meta. A tabela abaixo tem os mesmos números.">
      @for ($i = 0; $i <= 4; $i++)
        @php($yy = $y($i * $passoH * 60))
        <line class="g-grade" x1="{{ $esq }}" x2="{{ $larg - 8 }}" y1="{{ $yy }}" y2="{{ $yy }}"></line>
        <text class="g-eixo" x="{{ $esq - 6 }}" y="{{ $yy + 4 }}" text-anchor="end">{{ $i * $passoH }} h</text>
      @endfor
      @foreach (array_values($temposPorMes) as $i => $m)
        @php($cx = $esq + $grupo * $i + $grupo / 2)
        @foreach (['atendimento' => -1, 'reparo' => 1] as $chave => $lado)
          @php($x = $cx + ($lado < 0 ? -$barra - 1 : 1))
          @if ($m[$chave] !== null)
            <rect class="g-{{ $chave }}" x="{{ $x }}" y="{{ $y($m[$chave]) }}" width="{{ $barra }}" height="{{ max(0, $base - $y($m[$chave])) }}" rx="3">
              <title>{{ $m['rotulo'] }} · {{ $chave === 'atendimento' ? 'Atendimento' : 'Reparo' }} médio: {{ $tempo($m[$chave]) }}{{ $m['meta_'.$chave] !== null ? ' · meta '.$tempo($m['meta_'.$chave]) : '' }} · {{ $m['os'] }} OS</title>
            </rect>
          @endif
          @if ($m['meta_'.$chave] !== null)
            <line class="g-meta" x1="{{ $x - 3 }}" x2="{{ $x + $barra + 3 }}" y1="{{ $y($m['meta_'.$chave]) }}" y2="{{ $y($m['meta_'.$chave]) }}"><title>Meta de {{ $chave }}: {{ $tempo($m['meta_'.$chave]) }}</title></line>
          @endif
        @endforeach
        <text class="g-eixo" x="{{ $cx }}" y="{{ $base + 18 }}" text-anchor="middle">{{ $m['rotulo'] }}</text>
        @if (! $m['os'])<text class="g-eixo" x="{{ $cx }}" y="{{ $base - 6 }}" text-anchor="middle">sem OS</text>@endif
      @endforeach
      <line class="g-base" x1="{{ $esq }}" x2="{{ $larg - 8 }}" y1="{{ $base }}" y2="{{ $base }}"></line>
    </svg>
    <details class="grafico-tabela">
      <summary>Ver em tabela</summary>
      <div class="rolagem">
      <table class="tabela">
        <thead><tr><th>Mês</th><th class="num">OS</th><th class="num">Atendimento</th><th class="num">Meta</th><th class="num">Reparo</th><th class="num">Meta</th></tr></thead>
        <tbody>
        @foreach ($temposPorMes as $m)
          <tr><td>{{ $m['rotulo'] }}</td><td class="num">{{ $m['os'] }}</td>
            <td class="num">{{ $tempo($m['atendimento']) }}</td><td class="num suave">{{ $m['meta_atendimento'] !== null ? $tempo($m['meta_atendimento']) : '—' }}</td>
            <td class="num">{{ $tempo($m['reparo']) }}</td><td class="num suave">{{ $m['meta_reparo'] !== null ? $tempo($m['meta_reparo']) : '—' }}</td></tr>
        @endforeach
        </tbody>
      </table>
      </div>
    </details>
  </section>
@endif
