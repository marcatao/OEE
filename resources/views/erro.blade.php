@extends('layouts.portal')

@section('titulo', 'Erro')

@section('conteudo')
<section class="cartao">
  <h1>Não foi possível continuar</h1>
  <p class="erro">{{ $mensagem }}</p>
  <a href="{{ route('inicio') }}">Voltar ao início</a>
</section>
@endsection
