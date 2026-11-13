@extends('layouts.portal')

@section('titulo', 'Apontar horas')

@use('App\Services\Manutencao\HorasManutencao')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Ordens de serviço</p>
  <h1>Apontar horas</h1>
  @include('manutencao._erros')
  @if (! $mantenedor)
    <p class="aviso erro">Seu login não está ligado a um mantenedor. Peça para um administrador ligar em Ordens de serviço › Mantenedores.</p>
  @else
    <p class="suave">{{ $mantenedor->nome }} · jornada {{ \App\Support\JornadaSemanal::texto(\App\Support\JornadaSemanal::de($mantenedor)) }}. Hoje: <strong>{{ HorasManutencao::hhmm($hoje->sum(fn ($a) => $a->minutosTotais())) }}</strong> apontadas.</p>
  @endif

  @if ($rodando)
    <div class="os-rodando">
      <p>Rodando na <a href="{{ route('os.ordens.show', $rodando->ordem_servico_id) }}"><strong>{{ $rodando->ordemServico?->numeroFormatado() }}</strong></a> desde @dataHora($rodando->inicio):
        <strong class="tempo">{{ HorasManutencao::hhmm($rodando->minutosTotais()) }}</strong></p>
      <form method="post" action="{{ route('os.apontamentos.encerrar', $rodando) }}" class="formulario-vertical">
        @csrf
        <label>Descrição do serviço <textarea name="descricao" rows="3" maxlength="1000" required>{{ old('descricao', $rodando->descricao) }}</textarea></label>
        <label class="opcao"><input type="checkbox" name="concluir_os" value="1">
          <span><strong>Concluir a OS também</strong><small>marque se o serviço terminou; desmarcado, encerra só este apontamento e a OS continua aberta</small></span></label>
        <div class="acoes"><button class="botao" type="submit">Encerrar apontamento</button></div>
      </form>
    </div>
  @endif
</section>

<section class="cartao">
  <h2>OS para atender</h2>
  <p class="suave">As suas e as ainda sem responsável, por prioridade.</p>
  <div class="os-lista-cartoes">
  @forelse ($ordens as $os)
    <article class="cartao">
      <div class="os-cabecalho"><a href="{{ route('os.ordens.show', $os) }}"><strong>{{ $os->numeroFormatado() }}</strong></a>
        <span class="etiqueta os-{{ $os->status }}">{{ $os->rotuloStatus() }}</span><span class="prioridade-{{ $os->prioridade }}">{{ $os->rotuloPrioridade() }}</span></div>
      <div>@include('manutencao._maquina', ['maquina' => $os->maquina])@if ($os->maquina_parada) <span class="os-parada">parada</span>@endif</div>
      @if ($os->titulo)<strong>{{ $os->titulo }}</strong>@endif
      <div class="suave">{{ \Illuminate\Support\Str::limit($os->descricao, 140) }}</div>
      @if ($mantenedor && ! $rodando)
        <form method="post" action="{{ route('os.apontamentos.iniciar', $os) }}">@csrf<button class="botao" type="submit">@include('layouts.partials.icone', ['nome' => 'cronometro']) Iniciar</button></form>
      @endif
    </article>
  @empty
    <p class="suave">Nenhuma OS em aberto para você.</p>
  @endforelse
  </div>
</section>
@endsection
