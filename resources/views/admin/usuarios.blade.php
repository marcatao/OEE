@extends('layouts.portal')

@section('titulo', 'Usuários')

@section('conteudo')
<section class="cartao">
  <h1>Usuários</h1>
  <p>Nome e e-mail vêm do login pelo Entra ID. O perfil e a situação são definidos aqui.</p>
  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Situação</th><th>Último login</th><th></th></tr></thead>
    <tbody>
    @foreach ($usuarios as $u)
      <tr>
        <td>{{ $u->nome }}</td>
        <td>{{ $u->email }}</td>
        <td>
          <form id="usuario-{{ $u->id }}" method="post" action="{{ route('admin.usuarios.update', $u) }}">
            @csrf @method('put')
            <select name="perfil" aria-label="Perfil de {{ $u->nome }}">
              @foreach ($perfis as $p)<option value="{{ $p }}" @selected($u->perfil === $p)>{{ $p }}</option>@endforeach
            </select>
          </form>
        </td>
        <td>
          <select name="ativo" form="usuario-{{ $u->id }}" aria-label="Situação de {{ $u->nome }}">
            <option value="1" @selected($u->ativo)>ativo</option>
            <option value="0" @selected(! $u->ativo)>inativo</option>
          </select>
        </td>
        <td>@dataHora($u->ultimo_login_em)</td>
        <td><button class="botao pequeno" type="submit" form="usuario-{{ $u->id }}">Salvar</button></td>
      </tr>
    @endforeach
    </tbody>
  </table>
  </div>

  <h2>Últimos logins</h2>
  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Data/hora</th><th>E-mail</th><th>Resultado</th><th>IP</th></tr></thead>
    <tbody>
    @foreach ($logins as $l)
      <tr>
        <td>@dataHora($l->data_hora)</td>
        <td>{{ $l->email ?? '-' }}</td>
        <td class="{{ $l->sucesso ? '' : 'erro' }}">{{ $l->sucesso ? 'entrou' : 'recusado: '.$l->motivo }}</td>
        <td>{{ $l->ip }}</td>
      </tr>
    @endforeach
    </tbody>
  </table>
  </div>
</section>
@endsection
