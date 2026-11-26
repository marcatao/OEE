@extends('layouts.portal')

@section('titulo', 'Acesso às páginas')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Segurança</p>
  <h1>Acesso às páginas</h1>
  <p>Cada página do portal aberta por um usuário logado (não conta atualizações automáticas nem o aplicativo).
    @if ($retencao > 0) Registros guardados por {{ $retencao }} dias. @endif Sem data inicial, mostra os últimos 7 dias.</p>

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
    <label>Página <input type="search" name="pagina" value="{{ $f['pagina'] }}" placeholder="endereço ou rota (ex.: timeline)"></label>
    <label>IP <input type="search" name="ip" value="{{ $f['ip'] }}" placeholder="começa com" inputmode="decimal"></label>
    <button class="botao" type="submit">Filtrar</button>
    <a class="botao secundario" href="{{ route('cadastros.acessos-paginas') }}">Limpar</a>
  </form>

  <p class="suave">{{ $acessos->total() }} página(s) aberta(s).</p>
  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Data/hora</th><th>Usuário</th><th>Página</th><th>Endereço</th><th>IP</th></tr></thead>
    <tbody>
    @forelse ($acessos as $a)
      <tr>
        <td>@dataHora($a->data_hora)</td>
        <td>@include('partials.usuario-seguranca', ['usuario' => $a->usuario])</td>
        <td>{{ $rotulos[$a->rota] ?? ($a->rota ?? '-') }}</td>
        <td class="quebra"><code>{{ $a->caminho }}</code></td>
        <td>{{ $a->ip }}</td>
      </tr>
    @empty
      <tr><td colspan="5">Nenhuma página aberta no período.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  {{ $acessos->links('partials.paginacao') }}
</section>
@endsection
