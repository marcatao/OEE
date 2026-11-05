@extends('layouts.portal')

@section('titulo', 'Dashboard por máquina')

@use('App\Support\Formato')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Produção</p>
  <div class="titulo-com-acao">
    <div>
      <h1>Dashboard por máquina</h1>
      <p class="suave" style="margin:0">OEE de cada máquina em: <strong>{{ $periodo->rotulo() }}</strong>. Clique numa máquina para ver os detalhes.</p>
    </div>
    @include('dashboard._periodo', ['acao' => route('dashboard.maquinas')])
  </div>

  @php($geral = \App\Services\IndicadoresPeriodo::somar($indicadores))
  @if ($geral->temDados())
    <div class="numeros" style="margin-top:1rem">
      <div class="{{ Formato::nivel($geral->oee()) }}"><span>OEE geral</span><strong class="texto-nivel">{{ Formato::pct($geral->oee()) }}</strong></div>
      <div><span>Disponibilidade</span><strong>{{ Formato::pct($geral->disponibilidade()) }}</strong></div>
      <div><span>Performance</span><strong>{{ Formato::pct($geral->performance()) }}</strong></div>
      <div><span>Qualidade</span><strong>{{ Formato::pct($geral->qualidade()) }}</strong></div>
      <div><span>Peças boas</span><strong>{{ Formato::inteiro($geral->pecasBoas) }}</strong></div>
      <div><span>Tempo parado</span><strong>{{ Formato::minutos($geral->paradasNaoPlanejadasMin) }}</strong></div>
    </div>
  @endif
</section>

@if ($maquinas->isEmpty())
  <section class="cartao vazio">
    @include('layouts.partials.icone', ['nome' => 'maquina'])
    <p>Nenhuma máquina ativa cadastrada.</p>
    @if (auth()->user()->ehAdmin())
      <a class="botao" href="{{ route('admin.maquinas.create') }}">@include('layouts.partials.icone', ['nome' => 'mais']) Cadastrar máquina</a>
    @endif
  </section>
@else
  <div class="grade cartoes" style="margin-top:1.25rem">
    @foreach ($maquinas as $maquina)
      @php($ind = $indicadores[$maquina->id] ?? null)
      @php($oee = $ind?->oee())
      <a class="cartao-maquina {{ Formato::nivel($oee) }}" href="{{ route('dashboard.maquina', ['maquina' => $maquina, 'periodo' => $periodo->chave]) }}">
        <div class="medidor pequeno" style="--pct: {{ Formato::pct100($oee) }}"><div><strong>{{ Formato::pct($oee, 0) }}</strong></div></div>
        <div>
          <strong>{{ $maquina->nome }}</strong>
          <small>{{ $maquina->codigo }}@if ($maquina->localizacao()) · {{ $maquina->localizacao() }}@endif</small>
          @if (isset($paradasAbertas[$maquina->id]))
            <small class="etiqueta situacao-sem_sinal" style="margin-top:.35rem">Parada agora</small>
          @elseif (! $ind)
            <small>Sem apontamentos no período</small>
          @else
            <small>D {{ Formato::pct($ind->disponibilidade(), 0) }} · P {{ Formato::pct($ind->performance(), 0) }} · Q {{ Formato::pct($ind->qualidade(), 0) }}</small>
          @endif
        </div>
      </a>
    @endforeach
  </div>
@endif
@endsection
