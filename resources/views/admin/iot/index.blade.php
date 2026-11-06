@extends('layouts.portal')

@section('titulo', 'Dispositivos IoT')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Administração</p>
  <div class="titulo-com-acao">
    <h1>Dispositivos IoT</h1>
    <a class="botao" href="{{ route('admin.iot.create') }}">@include('layouts.partials.icone', ['nome' => 'mais']) Novo dispositivo</a>
  </div>
  <p class="suave">Sensores, CLPs, contadores e gateways que enviam os dados de produção e paradas das máquinas.</p>

  <form method="get" class="filtros">
    <label>Buscar <input name="busca" value="{{ $busca }}" placeholder="Nome, código ou endereço"></label>
    <button class="botao secundario" type="submit">Buscar</button>
  </form>

  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Código</th><th>Nome</th><th>Tipo</th><th>Protocolo</th><th>Endereço</th><th>Máquina</th><th>Último sinal</th><th>Situação</th><th></th></tr></thead>
    <tbody>
    @forelse ($dispositivos as $d)
      <tr>
        <td><code>{{ $d->codigo }}</code></td>
        <td>{{ $d->nome }}</td>
        <td>{{ $d->rotuloTipo() }}</td>
        <td>{{ $d->rotuloProtocolo() }}</td>
        <td>{{ $d->endereco ? $d->endereco.($d->porta ? ':'.$d->porta : '') : '-' }}</td>
        <td>{{ $d->maquina?->nome ?? '-' }}</td>
        <td>@dataHora($d->ultimo_sinal_em)</td>
        <td><span class="etiqueta situacao-{{ $d->situacao() }}"><i class="ponto"></i>{{ $d->rotuloSituacao() }}</span></td>
        <td><a class="botao pequeno secundario" href="{{ route('admin.iot.edit', $d) }}">Editar</a></td>
      </tr>
    @empty
      <tr><td colspan="9" class="suave">Nenhum dispositivo cadastrado.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  {{ $dispositivos->links('partials.paginacao') }}
</section>
@endsection
