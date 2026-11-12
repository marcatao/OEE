@extends('layouts.portal')

@section('titulo', $ss->exists ? 'Editar '.$ss->numeroFormatado() : 'Nova solicitação de serviço')

@use('App\Models\Manutencao\SolicitacaoServico')

@section('conteudo')
@php($autor = $ss->exists ? $ss->solicitante : auth()->user())
<section class="cartao">
  <p class="trilha">Ordens de serviço › <a href="{{ route('os.solicitacoes.index') }}">Solicitações</a></p>
  <div class="titulo-com-acao">
    <h1>{{ $ss->exists ? 'Editar '.$ss->numeroFormatado() : 'Nova solicitação de serviço' }}</h1>
    <span class="ss-autor">@if ($autor)@include('partials.avatar', ['usuario' => $autor])@endif
      <span><small class="suave">Solicitante</small><br><strong>{{ $autor?->nome }}</strong></span></span>
  </div>
  @include('manutencao._erros')

  <form method="post" class="formulario-vertical ss-form" action="{{ $ss->exists ? route('os.solicitacoes.update', $ss) : route('os.solicitacoes.store') }}">
    @csrf
    @if ($ss->exists) @method('put') @endif
    @include('manutencao.solicitacoes._campos')

    <div class="acoes">
      <button class="botao" type="submit">{{ $ss->exists ? 'Salvar' : 'Enviar solicitação' }}</button>
      <a class="botao secundario" href="{{ $ss->exists ? route('os.solicitacoes.show', $ss) : route('os.solicitacoes.index') }}">Cancelar</a>
    </div>
  </form>
</section>
@endsection
