@extends('layouts.portal')

@section('titulo', 'Erro 403')

@section('conteudo')
<section class="cartao">
  <h1>{{ 403 == 403 ? 'Acesso negado' : 'Página não encontrada' }}</h1>
  <p class="erro">{{ $exception->getMessage() ?: (403 == 403 ? 'Seu usuário não tem acesso a esta página.' : 'O endereço não existe.') }}</p>
  <a href="{{ route('inicio') }}">Voltar ao início</a>
</section>
@endsection
