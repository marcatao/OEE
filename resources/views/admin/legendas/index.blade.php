@extends('layouts.portal')

@section('titulo', 'Legendas')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Produção</p>
  <div class="titulo-com-acao">
    <h1>Legendas</h1>
    <a class="botao" href="{{ route('admin.legendas.create') }}">@include('layouts.partials.icone', ['nome' => 'mais']) Nova legenda</a>
  </div>
  <p class="suave">Estados das máquinas usados nos apontamentos. A <strong>cor</strong> pinta a barra da máquina na Timeline e o
    <strong>tipo</strong> define como o tempo entra na disponibilidade.</p>

  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Cor</th><th>Unidade</th><th>Sigla</th><th>Descrição</th><th>Tipo</th><th class="num">Ordem</th><th class="num">Apontamentos</th><th>Situação</th><th></th></tr></thead>
    <tbody>
    @forelse ($legendas as $l)
      <tr>
        <td><span class="amostra-cor" style="background: {{ $l->cor }}" title="{{ $l->cor }}"></span> <code>{{ $l->cor }}</code></td>
        <td><span class="etiqueta unidade-{{ $l->unidade }}">{{ $l->rotuloUnidade() }}</span></td>
        <td><strong>{{ $l->codigo }}</strong></td>
        <td>{{ $l->descricao }}</td>
        <td>{{ $l->rotuloTipo() }}</td>
        <td class="num">{{ $l->ordem }}</td>
        <td class="num">{{ $l->apontamentos_count }}</td>
        <td><span class="etiqueta {{ $l->ativo ? 'situacao-online' : 'situacao-inativo' }}">{{ $l->ativo ? 'Ativa' : 'Inativa' }}</span></td>
        <td><a class="botao pequeno secundario" href="{{ route('admin.legendas.edit', $l) }}">Editar</a></td>
      </tr>
    @empty
      <tr><td colspan="9" class="suave">Nenhuma legenda cadastrada.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
</section>
@endsection
