@extends('layouts.portal')

@section('titulo', 'Tipos de item do inventário')

@use('App\Models\InventarioTi\ItemTi')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Tecnologia › <a href="{{ route('inventario-ti.index') }}">Inventário de TI</a></p>
  <h1>Tipos de item</h1>
  <p class="suave">Cada tipo pertence a uma categoria (equipamento, licença de software ou linha/chip), que define os campos do cadastro
    e se o item tem termo de responsabilidade. A categoria de um tipo não muda; tipo sem uso pode ser desativado.</p>
  @include('manutencao._erros')

  <div class="rolagem">
  <table class="tabela tabela-form">
    <thead><tr><th>Categoria</th><th>Nome</th><th>Unidade</th><th>Situação</th><th>Itens</th><th></th></tr></thead>
    <tbody>
    @foreach ($tipos as $t)
      <tr>
        <td>{{ $t->rotuloCategoria() }}</td>
        <td><input name="nome" form="tipo-{{ $t->id }}" maxlength="60" required value="{{ $t->nome }}" aria-label="Nome"></td>
        <td>@include('partials.campo-unidade', ['registro' => $t, 'form' => 'tipo-'.$t->id, 'semRotulo' => true])</td>
        <td><select name="ativo" form="tipo-{{ $t->id }}" aria-label="Situação"><option value="1" @selected($t->ativo)>Ativo</option><option value="0" @selected(! $t->ativo)>Inativo</option></select></td>
        <td>{{ $t->itens_count }}</td>
        <td><form method="post" id="tipo-{{ $t->id }}" action="{{ route('inventario-ti.tipos.update', $t) }}">@csrf @method('put')<button class="botao pequeno secundario" type="submit">Salvar</button></form></td>
      </tr>
    @endforeach
    </tbody>
  </table>
  </div>

  <h2>Novo tipo</h2>
  <form method="post" action="{{ route('inventario-ti.tipos.store') }}" class="filtros">
    @csrf
    <input type="hidden" name="ativo" value="1">
    <label>Categoria
      <select name="categoria" required>@foreach (ItemTi::CATEGORIAS as $valor => $rotulo)<option value="{{ $valor }}">{{ $rotulo }}</option>@endforeach</select></label>
    <label>Nome <input name="nome" maxlength="60" required placeholder="Ex.: Leitor de código de barras"></label>
    @include('partials.campo-unidade', ['registro' => $novo])
    <button class="botao" type="submit">@include('layouts.partials.icone', ['nome' => 'mais']) Acrescentar</button>
  </form>
</section>
@endsection
