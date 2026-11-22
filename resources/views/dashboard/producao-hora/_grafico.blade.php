{{--
  Gráfico de barras de peças por hora (SVG, cores do portal.css). Parâmetros: $horas (ProducaoPorHora: hora, pecas,
  atual, futura, meta) e opcional $altura (unidades do desenho). A barra da hora atual fica destacada; as horas que
  ainda não chegaram aparecem só como trilho. Com meta, um traço tracejado na altura da meta de cada hora.
--}}
@php
  $passo = 48; $barra = 32; $altura ??= 200; $topo = 22; $base = 24;
  $util = $altura - $topo - $base;
  $n = max(1, count($horas));
  $largura = $n * $passo;
  $maximo = max(1, max(array_map(fn ($h) => max($h['pecas'], (float) ($h['meta'] ?? 0)), $horas ?: [['pecas' => 0]]))) * 1.12;
  $y = fn ($v) => round($topo + $util - ($v / $maximo) * $util, 2);
  $pulo = $n <= 16 ? 1 : 2;
@endphp
<svg class="ph-svg" viewBox="0 0 {{ $largura }} {{ $altura }}" role="img" aria-label="Peças por hora">
  @foreach ($horas as $i => $h)
    @php($x = $i * $passo + ($passo - $barra) / 2)
    <g class="ph-hora {{ $h['atual'] ? 'atual' : '' }} {{ $h['futura'] ? 'futura' : '' }}">
      <title>{{ $h['hora'] }} — {{ \App\Support\Formato::inteiro($h['pecas']) }} peças{{ $h['meta'] !== null ? ' · meta '.\App\Support\Formato::inteiro($h['meta']) : '' }}{{ $h['atual'] ? ' (hora atual)' : '' }}</title>
      <rect class="ph-trilho" x="{{ $x }}" y="{{ $topo }}" width="{{ $barra }}" height="{{ $util }}" rx="5"/>
      @if ($h['pecas'] > 0)
        <rect class="ph-barra" x="{{ $x }}" y="{{ $y($h['pecas']) }}" width="{{ $barra }}" height="{{ round($topo + $util - $y($h['pecas']), 2) }}" rx="5"/>
        <text class="ph-valor" x="{{ $x + $barra / 2 }}" y="{{ $y($h['pecas']) - 6 }}" text-anchor="middle">{{ \App\Support\Formato::inteiro($h['pecas']) }}</text>
      @endif
      @if ($h['meta'] !== null && $h['meta'] > 0)
        <line class="ph-meta" x1="{{ $i * $passo + 2 }}" x2="{{ ($i + 1) * $passo - 2 }}" y1="{{ $y($h['meta']) }}" y2="{{ $y($h['meta']) }}"/>
      @endif
      @if ($i % $pulo === 0 || $h['atual'])
        <text class="ph-eixo" x="{{ $x + $barra / 2 }}" y="{{ $altura - 6 }}" text-anchor="middle">{{ substr($h['hora'], 0, 2) }}h</text>
      @endif
    </g>
  @endforeach
</svg>
