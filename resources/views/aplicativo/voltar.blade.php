@extends('layouts.portal')

@section('titulo', 'Aplicativo')

@section('conteudo')
<section class="cartao">
  @isset($erro)
    <h1>Não foi possível entrar no aplicativo</h1>
    <p class="erro">{{ $erro }}</p>
  @else
    <h1>Pronto, {{ $nome }}!</h1>
    <p>Voltando para o aplicativo {{ config('oee.nome') }}…</p>
  @endisset
  <p><a class="botao" href="{{ $endereco }}">Voltar ao aplicativo</a></p>
</section>
@isset($nome)
<script>window.location.replace(@json($endereco));</script>
@endisset
@endsection
