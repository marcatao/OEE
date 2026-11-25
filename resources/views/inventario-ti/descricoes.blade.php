@extends('layouts.portal')

@section('titulo', 'Descrições do inventário')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Tecnologia › <a href="{{ route('inventario-ti.index') }}">Inventário de TI</a></p>
  <h1>Descrições</h1>
  <p class="suave">Descrições padrão dos itens, para todos escreverem igual (ex.: <strong>Notebook Dell Latitude 5440</strong>). No cadastro do item
    a descrição é escolhida desta lista. Com tipo, só aparece para itens daquele tipo; sem tipo, para qualquer um. Mudar o texto aqui
    não muda os itens já gravados; descrição sem uso pode ser desativada.</p>
  @include('manutencao._erros')

  <h2>Nova descrição</h2>
  <form method="post" action="{{ route('inventario-ti.descricoes.store') }}" class="filtros">
    @csrf
    <input type="hidden" name="ativo" value="1">
    <label>Descrição <input name="descricao" maxlength="150" required value="{{ old('descricao') }}" placeholder="Ex.: Notebook Dell Latitude 5440"></label>
    <label>Tipo
      <select name="tipo_id"><option value="">Qualquer tipo</option>
        @foreach ($tipos as $t)<option value="{{ $t->id }}" @selected((int) old('tipo_id') === $t->id)>{{ $t->nome }}</option>@endforeach
      </select></label>
    @include('partials.campo-unidade', ['registro' => $nova])
    <button class="botao" type="submit">@include('layouts.partials.icone', ['nome' => 'mais']) Acrescentar</button>
  </form>

  <h2>Descrições cadastradas</h2>
  <form method="get" class="filtros">
    <label>Buscar <input type="search" name="busca" value="{{ $busca }}" placeholder="Parte da descrição (ex.: dell 5440)" autofocus></label>
    <label>Tipo
      <select name="tipo"><option value="">Todos</option><option value="sem" @selected($tipoFiltro === 'sem')>Qualquer tipo (sem tipo)</option>
        @foreach ($tipos as $t)<option value="{{ $t->id }}" @selected($tipoFiltro === (string) $t->id)>{{ $t->nome }}</option>@endforeach
      </select></label>
    <label>Situação
      <select name="situacao"><option value="">Todas</option>
        <option value="ativas" @selected($situacao === 'ativas')>Ativas</option><option value="inativas" @selected($situacao === 'inativas')>Inativas</option>
      </select></label>
    <button class="botao secundario" type="submit">Buscar</button>
    @if ($busca !== '' || $tipoFiltro !== '' || $situacao !== '')<a href="{{ route('inventario-ti.descricoes') }}">Limpar</a>@endif
  </form>

  <div class="rolagem">
  <table class="tabela tabela-form">
    <thead><tr><th>Descrição</th><th>Tipo</th><th>Unidade</th><th>Situação</th><th>Itens</th><th></th></tr></thead>
    <tbody>
    @forelse ($descricoes as $d)
      <tr @class(['suave' => ! $d->ativo])>
        <td><input name="descricao" form="descricao-{{ $d->id }}" maxlength="150" required value="{{ $d->descricao }}" aria-label="Descrição"></td>
        <td><select name="tipo_id" form="descricao-{{ $d->id }}" aria-label="Tipo"><option value="">Qualquer tipo</option>
          @foreach ($tipos as $t)<option value="{{ $t->id }}" @selected((int) $d->tipo_id === $t->id)>{{ $t->nome }}</option>@endforeach
        </select></td>
        <td>@include('partials.campo-unidade', ['registro' => $d, 'form' => 'descricao-'.$d->id, 'semRotulo' => true])</td>
        <td><select name="ativo" form="descricao-{{ $d->id }}" aria-label="Situação"><option value="1" @selected($d->ativo)>Ativa</option><option value="0" @selected(! $d->ativo)>Inativa</option></select></td>
        <td>{{ $d->itens_count }}</td>
        <td><form method="post" id="descricao-{{ $d->id }}" action="{{ route('inventario-ti.descricoes.update', $d) }}">@csrf @method('put')<button class="botao pequeno secundario" type="submit">Salvar</button></form></td>
      </tr>
    @empty
      <tr><td colspan="6" class="suave">{{ $busca !== '' || $tipoFiltro !== '' || $situacao !== '' ? 'Nenhuma descrição encontrada.' : 'Nenhuma descrição cadastrada. Cadastre acima antes de incluir itens.' }}</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  {{ $descricoes->links('partials.paginacao') }}
</section>
@endsection
