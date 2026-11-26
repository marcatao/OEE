@extends('layouts.portal')

@section('titulo', 'Editar usuário')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Segurança › <a href="{{ route('cadastros.usuarios.index') }}">Usuários</a></p>
  <h1>{{ $usuario->nome }}</h1>

  <dl class="dados-curtos">
    <div><dt>E-mail</dt><dd>{{ $usuario->email }}</dd></div>
    <div><dt>Cadastrado em</dt><dd>@dataHora($usuario->criado_em)</dd></div>
    <div><dt>Último login</dt><dd>@dataHora($usuario->ultimo_login_em)</dd></div>
    <div><dt>Última página aberta</dt><dd>@dataHora($usuario->ultimo_acesso_em)@if ($usuario->ultimo_acesso_em) <a href="{{ route('cadastros.acessos-paginas', ['usuario' => $usuario->id]) }}">ver páginas</a>@endif</dd></div>
    <div><dt>Centro de custo</dt><dd>{{ $usuario->local_escritorio ?? '-' }}</dd></div>
    <div><dt>Departamento</dt><dd>{{ $usuario->departamento ?? '-' }}</dd></div>
    <div><dt>Gerente responsável</dt><dd>{{ $usuario->gerente_nome ?? '-' }}@if ($usuario->gerente_email) <small class="suave">{{ $usuario->gerente_email }}</small>@endif</dd></div>
  </dl>
  <p class="suave">Nome, e-mail, centro de custo (local de escritório), departamento e gerente vêm do Entra ID e são atualizados a cada login.</p>

  @if ($errors->any())<p class="aviso erro" role="alert">{{ $errors->first() }}</p>@endif

  <form method="post" action="{{ route('cadastros.usuarios.update', $usuario) }}" class="formulario-vertical">
    @csrf @method('put')

    <fieldset>
      <legend>Perfis</legend>
      <p class="suave">Marque um ou mais. Sem nenhum perfil, o usuário entra no portal mas não vê nenhuma página.
        Cada perfil dá acesso a uma unidade (<strong>Manutenção</strong>, <strong>Tecnologia</strong> ou <strong>Ferramentaria</strong>):
        o usuário enxerga as máquinas, plantas, mantenedores e solicitações das unidades dos perfis dele, e os cadastros marcados como
        <strong>Todas</strong>. Para dar acesso a mais de uma unidade, marque perfis em cada uma. Só quem é <strong>Solicitante</strong>
        (ou administrador) de uma área abre solicitações de serviço (SS) para ela.</p>
      @php($perfisMarcados = old('perfis', $usuario->perfisAcesso()))
      <div class="perfis-areas">
      @foreach (['manutencao' => 'Manutenção (produção)', 'tecnologia' => 'Tecnologia', 'ferramentaria' => 'Ferramentaria'] as $area => $tituloArea)
        <fieldset class="perfis-area">
          <legend><span class="etiqueta unidade-{{ $area }}">{{ $tituloArea }}</span></legend>
          @foreach ($perfis as $valor => $perfil)
            @continue(($perfil['area'] ?? 'manutencao') !== $area)
            <label class="opcao">
              <input type="checkbox" name="perfis[]" value="{{ $valor }}" @checked(in_array($valor, $perfisMarcados, true))>
              <span><strong>{{ $perfil['rotulo'] }}</strong><small>{{ $perfil['descricao'] }}</small></span>
            </label>
          @endforeach
        </fieldset>
      @endforeach
      </div>
    </fieldset>

    <fieldset class="opcoes-linha">
      <legend>Situação</legend>
      <label class="opcao"><input type="radio" name="ativo" value="1" @checked(old('ativo', (int) $usuario->ativo) == 1)> <span>Ativo</span></label>
      <label class="opcao"><input type="radio" name="ativo" value="0" @checked(old('ativo', (int) $usuario->ativo) == 0)> <span>Inativo <small>bloqueia a entrada no portal na hora</small></span></label>
    </fieldset>

    <fieldset>
      <legend>Grupos</legend>
      @php($marcados = old('grupos', $usuario->grupos->pluck('id')->all()))
      <div class="grade-opcoes">
      @forelse ($grupos as $grupo)
        <label class="opcao">
          <input type="checkbox" name="grupos[]" value="{{ $grupo->id }}" @checked(in_array($grupo->id, $marcados))>
          <span>{{ $grupo->nome }} @unless ($grupo->ativo)<small>(grupo inativo)</small>@endunless
            @if ($grupo->descricao)<small>{{ $grupo->descricao }}</small>@endif</span>
        </label>
      @empty
        <p class="suave">Nenhum grupo cadastrado. <a href="{{ route('cadastros.grupos.create') }}">Criar um grupo</a>.</p>
      @endforelse
      </div>
    </fieldset>

    <fieldset>
      <legend>Páginas liberadas só para este usuário</legend>
      <p class="suave">Além das páginas dos perfis e dos grupos. Vale só enquanto o usuário estiver ativo e com pelo menos um perfil.
        As marcadas como <strong>dados pessoais</strong> (controle de acessos da Segurança) não vêm com nenhum perfil, exceto o
        Administrador de Tecnologia: libere aqui ou num grupo.</p>
      @php($diretas = old('permissoes', $permissoesDiretas))
      <div class="grade-opcoes largas">
      @foreach ($catalogo as $chave => $rotulo)
        <label class="opcao"><input type="checkbox" name="permissoes[]" value="{{ $chave }}" @checked(in_array($chave, $diretas, true))>
          <span>{{ $rotulo }}@if (in_array($chave, $restritas, true)) <small class="erro">dados pessoais</small>@endif</span></label>
      @endforeach
      </div>
    </fieldset>

    <fieldset>
      <legend>Protheus <small class="suave">(opcional)</small></legend>
      <p class="suave">Liga este usuário do portal ao usuário dele no Protheus.</p>
      <div class="campos">
        <label>Usuário Protheus
          <input name="usuario_protheus" maxlength="25" value="{{ old('usuario_protheus', $usuario->usuario_protheus) }}" placeholder="ex.: JSILVA" autocomplete="off">
          <small class="suave">Nome de login no Protheus.</small></label>
        <label>ID Protheus
          <input name="id_protheus" maxlength="20" value="{{ old('id_protheus', $usuario->id_protheus) }}" placeholder="ex.: 000123" autocomplete="off">
          <small class="suave">Código do usuário no Protheus.</small></label>
      </div>
    </fieldset>

    <div class="acoes">
      <button class="botao" type="submit">Salvar</button>
      <a class="botao secundario" href="{{ route('cadastros.usuarios.index') }}">Cancelar</a>
    </div>
  </form>

  <h2>Celulares com o aplicativo</h2>
  @if ($celulares->isEmpty())
    <p class="suave">Nenhum celular conectado.</p>
  @else
  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Celular</th><th>Versão</th><th>Conectado em</th><th>Último uso</th><th></th></tr></thead>
    <tbody>
    @foreach ($celulares as $c)
      <tr><td>{{ $c->dispositivo ?? '-' }}</td><td>{{ $c->versao_app ?? '-' }}</td><td>@dataHora($c->criado_em)</td><td>@dataHora($c->ultimo_uso_em)</td>
        <td><form method="post" action="{{ route('cadastros.usuarios.revogar-celular', [$usuario, $c->id]) }}" onsubmit="return confirm('Desconectar este celular?')">@csrf
          <button class="botao pequeno secundario" type="submit">Desconectar</button></form></td></tr>
    @endforeach
    </tbody>
  </table>
  </div>
  @endif

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
