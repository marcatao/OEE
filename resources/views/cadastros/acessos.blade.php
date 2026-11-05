@extends('layouts.portal')

@section('titulo', 'Histórico de acessos')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Cadastros › Logins</p>
  <h1>Histórico de acessos</h1>
  <p>Todas as tentativas de login pelo Entra ID, gravadas no banco.</p>

  <form method="get" class="filtros">
    <label>Resultado
      <select name="resultado">
        <option value="">Todos</option>
        <option value="entrou" @selected($resultado === 'entrou')>Entrou</option>
        <option value="recusado" @selected($resultado === 'recusado')>Recusado</option>
      </select>
    </label>
    <button class="botao" type="submit">Filtrar</button>
  </form>

  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Data/hora</th><th>Usuário</th><th>E-mail</th><th>Resultado</th><th>IP</th></tr></thead>
    <tbody>
    @forelse ($logins as $l)
      <tr>
        <td>@dataHora($l->data_hora)</td>
        <td>@if ($l->usuario)<a href="{{ route('cadastros.usuarios.edit', $l->usuario) }}">{{ $l->usuario->nome }}</a>@else - @endif</td>
        <td>{{ $l->email ?? '-' }}</td>
        <td class="{{ $l->sucesso ? '' : 'erro' }}">{{ $l->sucesso ? 'Entrou' : 'Recusado' }}{{ $l->motivo ? ': '.$l->motivo : '' }}</td>
        <td>{{ $l->ip }}</td>
      </tr>
    @empty
      <tr><td colspan="5">Nenhum acesso registrado.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  {{ $logins->links('partials.paginacao') }}
</section>
@endsection
