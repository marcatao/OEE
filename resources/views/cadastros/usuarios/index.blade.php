@extends('layouts.portal')

@section('titulo', 'Usuários')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Segurança</p>
  <h1>Usuários</h1>
  <p>Os usuários são cadastrados automaticamente no primeiro login pelo Entra ID e nascem <strong>sem acesso</strong>.
    Aqui você define o perfil, a situação e os grupos de cada um.</p>

  @if ($pendentes)
    <p class="aviso alerta">{{ $pendentes }} {{ $pendentes == 1 ? 'usuário aguarda' : 'usuários aguardam' }} liberação.</p>
  @endif

  <form method="get" class="filtros" role="search">
    <label>Buscar<input type="search" name="busca" value="{{ request('busca') }}" placeholder="Nome, e-mail, centro de custo ou departamento"></label>
    <label>Perfil
      <select name="perfil">
        <option value="">Todos</option>
        @foreach ($perfis as $valor => $rotulo)
          <option value="{{ $valor }}" @selected(request('perfil') === $valor)>{{ $rotulo }}</option>
        @endforeach
      </select>
    </label>
    <button class="botao" type="submit">Filtrar</button>
  </form>

  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Nome</th><th>E-mail</th><th>Centro de custo</th><th>Departamento</th><th>Situação</th><th>Último login</th><th></th></tr></thead>
    <tbody>
    @forelse ($usuarios as $usuario)
      <tr>
        <td><span style="display:inline-flex;align-items:center;gap:.6rem">@include('partials.avatar', ['usuario' => $usuario]) <span>{{ $usuario->nome }}@if ($usuario->cargo)<br><small class="suave">{{ $usuario->cargo }}</small>@endif</span></span></td>
        <td>{{ $usuario->email }}</td>
        <td class="quebra">{{ $usuario->local_escritorio ?? '-' }}</td>
        <td class="quebra">{{ $usuario->departamento ?? '-' }}</td>
        <td>{{ $usuario->ativo ? 'Ativo' : 'Inativo' }}</td>
        <td>@dataHora($usuario->ultimo_login_em)</td>
        <td><a class="botao pequeno" href="{{ route('cadastros.usuarios.edit', $usuario) }}">Editar</a></td>
      </tr>
    @empty
      <tr><td colspan="7">Nenhum usuário encontrado.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  {{ $usuarios->links('partials.paginacao') }}
</section>
@endsection
