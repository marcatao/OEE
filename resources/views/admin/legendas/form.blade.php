@extends('layouts.portal')

@section('titulo', $legenda->exists ? 'Editar legenda' : 'Nova legenda')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Produção › <a href="{{ route('admin.legendas.index') }}">Legendas</a></p>
  <h1>{{ $legenda->exists ? $legenda->codigo.' — '.$legenda->descricao : 'Nova legenda' }}</h1>

  @if ($errors->any())<p class="aviso erro" role="alert">{{ $errors->first() }}</p>@endif

  <form method="post" class="formulario-vertical"
        action="{{ $legenda->exists ? route('admin.legendas.update', $legenda) : route('admin.legendas.store') }}">
    @csrf
    @if ($legenda->exists) @method('put') @endif

    <div class="campos">
        @include('partials.campo-unidade', ['registro' => $legenda])
      <label>Sigla
        <input name="codigo" required maxlength="12" value="{{ old('codigo', $legenda->codigo) }}" placeholder="Ex.: DT" style="text-transform:uppercase">
        <small>Aparece dentro da barra na timeline.</small>
      </label>
      <label>Descrição
        <input name="descricao" required maxlength="120" value="{{ old('descricao', $legenda->descricao) }}" placeholder="Ex.: Parada não planejada">
      </label>
      <label>Tipo
        <select name="tipo" required>
          @foreach (\App\Models\Legenda::TIPOS as $chave => $rotulo)
            <option value="{{ $chave }}" @selected(old('tipo', $legenda->tipo) === $chave)>{{ $rotulo }}</option>
          @endforeach
        </select>
        <small>Produzindo e paradas não planejadas entram na disponibilidade; planejadas e desligada ficam fora.</small>
      </label>
      <label>Cor na timeline
        <span style="display:flex;gap:.75rem;align-items:center">
          <input type="color" name="cor" required value="{{ old('cor', $legenda->cor) }}" aria-label="Cor da legenda">
          <span class="suave">Preenche a barra da máquina na Timeline.</span>
        </span>
      </label>
      <label>Ordem
        <input name="ordem" type="number" min="0" max="9999" value="{{ old('ordem', $legenda->ordem) }}">
        <small>Posição nas listas e no resumo.</small>
      </label>
    </div>

    <fieldset class="opcoes-linha">
      <legend>Situação</legend>
      <label class="opcao"><input type="radio" name="ativo" value="1" @checked(old('ativo', (int) $legenda->ativo) == 1)> <span>Ativa</span></label>
      <label class="opcao"><input type="radio" name="ativo" value="0" @checked(old('ativo', (int) $legenda->ativo) == 0)> <span>Inativa <small>não aparece em apontamentos novos; o histórico continua com a cor</small></span></label>
    </fieldset>

    <div class="acoes">
      <button class="botao" type="submit">Salvar</button>
      <a class="botao secundario" href="{{ route('admin.legendas.index') }}">Cancelar</a>
    </div>
  </form>
</section>
@endsection
