@extends('layouts.portal')

@section('titulo', 'Calculadora')

@php
  $pct = fn (float $v) => number_format($v * 100, 1, ',', '.').'%';
  $campos = [
    'tempo_planejado' => ['Tempo planejado (min)', 'any'],
    'paradas' => ['Paradas (min)', 'any'],
    'ciclo_ideal' => ['Ciclo ideal (s/peça)', 'any'],
    'total_produzido' => ['Total produzido (peças)', '1'],
    'pecas_boas' => ['Peças boas', '1'],
  ];
@endphp

@section('conteudo')
<section class="cartao">
  <h1>Calculadora de OEE</h1>
  <form method="post" action="{{ route('calculadora') }}" class="formulario">
    @csrf
    @foreach ($campos as $nome => [$rotulo, $passo])
      <label>{{ $rotulo }}
        <input name="{{ $nome }}" type="number" step="{{ $passo }}" min="0" required value="{{ old($nome, request($nome)) }}">
      </label>
    @endforeach
    <button class="botao" type="submit">Calcular</button>
  </form>

  @if ($errors->any())<p class="erro">{{ $errors->first() }}</p>@endif
  @if ($erro)<p class="erro">{{ $erro }}</p>@endif

  @if ($resultado)
  <div class="indicadores">
    <div><span>Disponibilidade</span><strong>{{ $pct($resultado->disponibilidade) }}</strong></div>
    <div><span>Performance</span><strong>{{ $pct($resultado->performance) }}</strong></div>
    <div><span>Qualidade</span><strong>{{ $pct($resultado->qualidade) }}</strong></div>
    <div class="destaque"><span>OEE</span><strong>{{ $pct($resultado->oee()) }}</strong></div>
  </div>
  @endif
</section>
@endsection
