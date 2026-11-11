@extends('layouts.portal')

@section('titulo', 'Grupos')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Segurança</p>
  <div class="titulo-com-acao">
    <h1>Grupos</h1>
    <a class="botao" href="{{ route('cadastros.grupos.create') }}">Novo grupo</a>
  </div>
  <p>Os grupos definem quais páginas os usuários podem abrir. Um usuário pode estar em vários grupos.</p>

  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Nome</th><th>Descrição</th><th>Permissões</th><th class="num">Usuários</th><th>Situação</th><th></th></tr></thead>
    <tbody>
    @forelse ($grupos as $grupo)
      <tr>
        <td>{{ $grupo->nome }}</td>
        <td class="quebra">{{ $grupo->descricao ?? '-' }}</td>
        <td class="quebra">{{ collect($grupo->permissoes())->map(fn ($p) => $catalogo[$p] ?? $p)->join(', ') ?: 'Nenhuma' }}</td>
        <td class="num">{{ $grupo->usuarios_count }}</td>
        <td>{{ $grupo->ativo ? 'Ativo' : 'Inativo' }}</td>
        <td><a class="botao pequeno" href="{{ route('cadastros.grupos.edit', $grupo) }}">Editar</a></td>
      </tr>
    @empty
      <tr><td colspan="6">Nenhum grupo cadastrado ainda.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
</section>
@endsection
