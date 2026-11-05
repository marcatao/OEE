@extends('layouts.portal')

@section('titulo', $grupo->exists ? 'Editar grupo' : 'Novo grupo')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Administração › Logins › <a href="{{ route('cadastros.grupos.index') }}">Grupos</a></p>
  <h1>{{ $grupo->exists ? $grupo->nome : 'Novo grupo' }}</h1>

  @if ($errors->any())<p class="aviso erro" role="alert">{{ $errors->first() }}</p>@endif

  <form method="post" class="formulario-vertical"
        action="{{ $grupo->exists ? route('cadastros.grupos.update', $grupo) : route('cadastros.grupos.store') }}">
    @csrf
    @if ($grupo->exists) @method('put') @endif

    <label>Nome
      <input name="nome" required maxlength="80" value="{{ old('nome', $grupo->nome) }}" placeholder="Ex.: Supervisores da Linha 1">
    </label>
    <label>Descrição
      <input name="descricao" maxlength="300" value="{{ old('descricao', $grupo->descricao) }}">
    </label>

    <fieldset>
      <legend>Situação</legend>
      <label class="opcao"><input type="radio" name="ativo" value="1" @checked(old('ativo', (int) $grupo->ativo) == 1)> <span>Ativo</span></label>
      <label class="opcao"><input type="radio" name="ativo" value="0" @checked(old('ativo', (int) $grupo->ativo) == 0)> <span>Inativo <small>os membros perdem as permissões deste grupo</small></span></label>
    </fieldset>

    <fieldset>
      <legend>Permissões (páginas que os membros podem abrir)</legend>
      @php($marcadas = old('permissoes', $permissoes))
      @foreach ($catalogo as $chave => $rotulo)
        <label class="opcao"><input type="checkbox" name="permissoes[]" value="{{ $chave }}" @checked(in_array($chave, $marcadas))> <span>{{ $rotulo }}</span></label>
      @endforeach
    </fieldset>

    <fieldset>
      <legend>Usuários do grupo</legend>
      @php($marcados = array_map('intval', old('usuarios', $membros)))
      <div class="lista-marcar">
      @forelse ($usuarios as $usuario)
        <label class="opcao">
          <input type="checkbox" name="usuarios[]" value="{{ $usuario->id }}" @checked(in_array($usuario->id, $marcados))>
          <span>{{ $usuario->nome }} <small>{{ $usuario->email }} · {{ $usuario->rotuloPerfil() }}</small></span>
        </label>
      @empty
        <p class="suave">Nenhum usuário cadastrado ainda (eles aparecem após o primeiro login).</p>
      @endforelse
      </div>
    </fieldset>

    <div class="acoes">
      <button class="botao" type="submit">Salvar</button>
      <a class="botao secundario" href="{{ route('cadastros.grupos.index') }}">Cancelar</a>
    </div>
  </form>
</section>
@endsection
