@extends('layouts.portal')

@section('titulo', 'Histórico de logins')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Segurança</p>
  <h1>Histórico de logins</h1>
  <p>Todas as tentativas de login pelo Entra ID, gravadas no banco. Datas no horário local.</p>

  <form method="get" class="filtros">
    <label>De <input type="date" name="de" value="{{ $f['de'] }}"></label>
    <label>Até <input type="date" name="ate" value="{{ $f['ate'] }}"></label>
    <label>Usuário
      <select name="usuario" data-busca>
        <option value="">Todos</option>
        @foreach ($usuarios as $u)
          <option value="{{ $u->id }}" @selected((int) $f['usuario'] === (int) $u->id)>{{ $u->nome }}{{ $u->email ? ' — '.$u->email : '' }}</option>
        @endforeach
      </select>
    </label>
    <label>E-mail <input type="search" name="email" value="{{ $f['email'] }}" placeholder="parte do e-mail"></label>
    <label>Resultado
      <select name="resultado">
        <option value="">Todos</option>
        <option value="entrou" @selected($resultado === 'entrou')>Entrou</option>
        <option value="recusado" @selected($resultado === 'recusado')>Recusado</option>
      </select>
    </label>
    <label>Motivo
      <select name="motivo">
        <option value="">Todos</option>
        <option value="-" @selected($f['motivo'] === '-')>(sem motivo)</option>
        @foreach ($motivos as $m)
          <option value="{{ $m }}" @selected($f['motivo'] === $m)>{{ $m }}</option>
        @endforeach
      </select>
    </label>
    <label>IP <input type="search" name="ip" value="{{ $f['ip'] }}" placeholder="começa com" inputmode="decimal"></label>
    <button class="botao" type="submit">Filtrar</button>
    <a class="botao secundario" href="{{ route('cadastros.acessos') }}">Limpar</a>
  </form>

  <p class="suave">{{ $logins->total() }} registro(s).</p>
  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Data/hora</th><th>Usuário</th><th>E-mail</th><th>Resultado</th><th>IP</th><th>Navegador</th></tr></thead>
    <tbody>
    @forelse ($logins as $l)
      <tr>
        <td>@dataHora($l->data_hora)</td>
        <td>@include('partials.usuario-seguranca', ['usuario' => $l->usuario])</td>
        <td>{{ $l->email ?? '-' }}</td>
        <td class="{{ $l->sucesso ? '' : 'erro' }}">{{ $l->sucesso ? 'Entrou' : 'Recusado' }}{{ $l->motivo ? ': '.$l->motivo : '' }}</td>
        <td>{{ $l->ip }}</td>
        <td title="{{ $l->navegador }}">{{ \App\Services\Seguranca\SessoesAtivas::descreverNavegador($l->navegador) }}</td>
      </tr>
    @empty
      <tr><td colspan="6">Nenhum acesso encontrado.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  {{ $logins->links('partials.paginacao') }}
</section>
@endsection
