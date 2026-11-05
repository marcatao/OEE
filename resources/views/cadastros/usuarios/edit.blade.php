@extends('layouts.portal')

@section('titulo', 'Editar usuário')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Administração › Logins › <a href="{{ route('cadastros.usuarios.index') }}">Usuários</a></p>
  <h1>{{ $usuario->nome }}</h1>

  <dl class="dados-curtos">
    <div><dt>E-mail</dt><dd>{{ $usuario->email }}</dd></div>
    <div><dt>Cadastrado em</dt><dd>@dataHora($usuario->criado_em)</dd></div>
    <div><dt>Último login</dt><dd>@dataHora($usuario->ultimo_login_em)</dd></div>
  </dl>
  <p class="suave">Nome e e-mail vêm do Entra ID e são atualizados a cada login.</p>

  @if ($errors->any())<p class="aviso erro" role="alert">{{ $errors->first() }}</p>@endif

  <form method="post" action="{{ route('cadastros.usuarios.update', $usuario) }}" class="formulario-vertical">
    @csrf @method('put')

    <fieldset>
      <legend>Perfil</legend>
      @foreach ($perfis as $valor => $rotulo)
        <label class="opcao">
          <input type="radio" name="perfil" value="{{ $valor }}" @checked(old('perfil', $usuario->perfil) === $valor)>
          <span><strong>{{ $rotulo }}</strong>
            <small>{{ match ($valor) {
              'sem_acesso' => 'Entra no portal, mas não vê nenhuma página.',
              'operador' => 'Vê as páginas permitidas pelos grupos dele.',
              'admin' => 'Vê tudo e administra usuários, grupos e o banco.',
            } }}</small></span>
        </label>
      @endforeach
    </fieldset>

    <fieldset>
      <legend>Situação</legend>
      <label class="opcao"><input type="radio" name="ativo" value="1" @checked(old('ativo', (int) $usuario->ativo) == 1)> <span>Ativo</span></label>
      <label class="opcao"><input type="radio" name="ativo" value="0" @checked(old('ativo', (int) $usuario->ativo) == 0)> <span>Inativo <small>bloqueia a entrada no portal na hora</small></span></label>
    </fieldset>

    <fieldset>
      <legend>Grupos</legend>
      @php($marcados = old('grupos', $usuario->grupos->pluck('id')->all()))
      @forelse ($grupos as $grupo)
        <label class="opcao">
          <input type="checkbox" name="grupos[]" value="{{ $grupo->id }}" @checked(in_array($grupo->id, $marcados))>
          <span>{{ $grupo->nome }} @unless ($grupo->ativo)<small>(grupo inativo)</small>@endunless
            @if ($grupo->descricao)<small>{{ $grupo->descricao }}</small>@endif</span>
        </label>
      @empty
        <p class="suave">Nenhum grupo cadastrado. <a href="{{ route('cadastros.grupos.create') }}">Criar um grupo</a>.</p>
      @endforelse
    </fieldset>

    <div class="acoes">
      <button class="botao" type="submit">Salvar</button>
      <a class="botao secundario" href="{{ route('cadastros.usuarios.index') }}">Cancelar</a>
    </div>
  </form>

  <h2>Últimos acessos</h2>
  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Data/hora</th><th>Resultado</th><th>IP</th></tr></thead>
    <tbody>
    @forelse ($acessos as $a)
      <tr><td>@dataHora($a->data_hora)</td>
        <td class="{{ $a->sucesso ? '' : 'erro' }}">{{ $a->sucesso ? 'Entrou' : 'Recusado' }}{{ $a->motivo ? ': '.$a->motivo : '' }}</td>
        <td>{{ $a->ip }}</td></tr>
    @empty
      <tr><td colspan="3">Sem acessos registrados.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
</section>
@endsection
