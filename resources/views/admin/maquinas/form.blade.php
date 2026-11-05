@extends('layouts.portal')

@section('titulo', $maquina->exists ? 'Editar máquina' : 'Nova máquina')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Administração › <a href="{{ route('admin.maquinas.index') }}">Máquinas</a></p>
  <h1>{{ $maquina->exists ? $maquina->nome : 'Nova máquina' }}</h1>

  @if ($errors->any())<p class="aviso erro" role="alert">{{ $errors->first() }}</p>@endif

  <form method="post" class="formulario-vertical"
        action="{{ $maquina->exists ? route('admin.maquinas.update', $maquina) : route('admin.maquinas.store') }}">
    @csrf
    @if ($maquina->exists) @method('put') @endif

    <div class="campos">
      <label>Código
        <input name="codigo" required maxlength="30" value="{{ old('codigo', $maquina->codigo) }}" placeholder="Ex.: INJ-01">
      </label>
      <label>Nome
        <input name="nome" required maxlength="120" value="{{ old('nome', $maquina->nome) }}" placeholder="Ex.: Injetora 01">
      </label>
      <label>Linha
        <select name="linha_id" required>
          <option value="">Escolha…</option>
          @foreach ($linhas as $planta => $doGrupo)
            <optgroup label="{{ $planta }}">
              @foreach ($doGrupo as $linha)
                <option value="{{ $linha->id }}" @selected((int) old('linha_id', $maquina->linha_id) === $linha->id)>{{ $linha->nome }}</option>
              @endforeach
            </optgroup>
          @endforeach
        </select>
      </label>
      <label>Ciclo ideal (segundos por peça)
        <input name="ciclo_ideal_s" type="number" step="any" min="0" value="{{ old('ciclo_ideal_s', $maquina->ciclo_ideal_s) }}">
        <small>Copiado para cada apontamento; mudar aqui não altera o histórico.</small>
      </label>
      <label>Fabricante
        <input name="fabricante" maxlength="120" value="{{ old('fabricante', $maquina->fabricante) }}">
      </label>
      <label>Modelo
        <input name="modelo" maxlength="120" value="{{ old('modelo', $maquina->modelo) }}">
      </label>
    </div>

    <fieldset>
      <legend>Situação</legend>
      <label class="opcao"><input type="radio" name="ativo" value="1" @checked(old('ativo', (int) $maquina->ativo) == 1)> <span>Ativa</span></label>
      <label class="opcao"><input type="radio" name="ativo" value="0" @checked(old('ativo', (int) $maquina->ativo) == 0)> <span>Inativa <small>sai dos painéis, mas o histórico continua</small></span></label>
    </fieldset>

    <div class="acoes">
      <button class="botao" type="submit">Salvar</button>
      <a class="botao secundario" href="{{ route('admin.maquinas.index') }}">Cancelar</a>
    </div>
  </form>
</section>
@endsection
