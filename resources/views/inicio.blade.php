@extends('layouts.portal')

@section('conteudo')
<section class="cartao">
  <h1>{{ config('app.name') }}</h1>
  <p>Eficiência Global dos Equipamentos: <strong>Disponibilidade × Performance × Qualidade</strong>.</p>
  @auth
    @if (! auth()->user()->temAcesso())
      @include('partials.aguardando')
    @else
      <p>Olá, {{ auth()->user()->nome }}! Use o menu ao lado para navegar.</p>
    @endif
  @else
    <p>Entre com sua conta corporativa (Microsoft Entra ID) para acessar.</p>
    <a class="botao" href="{{ route('login') }}">Entrar com Microsoft</a>
  @endauth
</section>
@endsection
