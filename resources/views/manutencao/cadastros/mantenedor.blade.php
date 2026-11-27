@extends('layouts.portal')

@section('titulo', $m->exists ? $m->nomeExibicao() : 'Novo mantenedor')

@use('App\Models\Manutencao\Mantenedor')

@section('conteudo')
@php($areasMarcadas = array_map('intval', old('areas', $m->exists ? $m->areas->pluck('id')->all() : [])))
<section class="cartao">
  <p class="trilha">Ordens de serviço › <a href="{{ route('os.cadastros.mantenedores') }}">Mantenedores</a></p>
  <h1>{{ $m->exists ? $m->nomeExibicao() : 'Novo mantenedor' }}</h1>
  <p class="suave">O mantenedor é um usuário do portal: nome, foto, e-mail e departamento vêm do login (Segurança › Usuários). Aqui ficam só
    os dados da manutenção.</p>
  @include('manutencao._erros')

  <form method="post" class="formulario-vertical" action="{{ $m->exists ? route('os.cadastros.mantenedores.update', $m) : route('os.cadastros.mantenedores.store') }}">
    @csrf
    @if ($m->exists) @method('put') @endif

    <label>Usuário (login)
      <select name="usuario_id" id="mant-usuario" data-busca="Buscar usuário por nome ou e-mail…" @if (! $m->exists || $m->usuario_id) required @endif>
        <option value="">{{ $m->exists && ! $m->usuario_id ? 'Sem login (cadastro antigo: '.$m->nome.')' : 'Escolha o usuário…' }}</option>
        @foreach ($usuarios as $u)
          <option value="{{ $u->id }}" @selected((int) old('usuario_id', $m->usuario_id) === $u->id)
            data-email="{{ $u->email }}" data-depto="{{ $u->departamento }}" data-cargo="{{ $u->cargo }}" data-cc="{{ $u->local_escritorio }}"
            data-foto="{{ $u->temFoto() ? $u->urlFoto() : '' }}" data-iniciais="{{ $u->iniciais() }}">{{ $u->nome }} — {{ $u->email }}</option>
        @endforeach
      </select>
      <small>Não achou? A pessoa precisa entrar no portal uma vez com a conta Microsoft.</small>
    </label>

    <div class="perfil-cabecalho" id="mant-dados" @if (! $m->usuario) hidden @endif>
      <span class="avatar grande" id="mant-avatar">@if ($m->usuario?->temFoto())<img src="{{ $m->usuario->urlFoto() }}" alt="">@else{{ $m->usuario?->iniciais() }}@endif</span>
      <dl class="dados-curtos">
        <div><dt>E-mail</dt><dd id="mant-email">{{ $m->usuario?->email }}</dd></div>
        <div><dt>Departamento</dt><dd id="mant-depto">{{ $m->usuario?->departamento ?: '—' }}</dd></div>
        <div><dt>Cargo</dt><dd id="mant-cargo">{{ $m->usuario?->cargo ?: '—' }}</dd></div>
        <div><dt>Centro de custo (Entra)</dt><dd id="mant-cc">{{ $m->usuario?->local_escritorio ?: '—' }}</dd></div>
      </dl>
    </div>

    <div class="formulario-bloco">
      @include('partials.escolha-multipla', [
        'nome' => 'areas[]', 'titulo' => 'Áreas executantes (aprova, atende e vê as SS/OS)', 'vazio' => 'Nenhuma — marque pelo menos uma',
        'busca' => 'Buscar área…', 'aberto' => ! $m->exists || $errors->has('areas'),
        'semItens' => 'Cadastre as áreas em Ordens de serviço › Áreas executantes.',
        'itens' => $areas->map(fn ($a) => ['valor' => $a->id, 'nome' => $a->nome, 'detalhe' => $a->rotuloUnidade(), 'marcado' => in_array($a->id, $areasMarcadas, true)])->all(),
      ])
    </div>
    @php($ferramentasMarcadas = array_map('intval', old('ferramentas', $m->exists ? $m->ferramentas()->pluck('id')->all() : [])))
    <div class="formulario-bloco">
      @include('partials.escolha-multipla', [
        'nome' => 'ferramentas[]', 'titulo' => 'Equipamentos que estão com ele', 'vazio' => 'Nenhum',
        'busca' => 'Buscar equipamento por código ou descrição…',
        'semItens' => 'Nenhum equipamento avulso. Cadastre em Ordens de serviço › Equipamentos.',
        'itens' => $ferramentas->map(fn ($f) => ['valor' => $f->id, 'nome' => $f->codigo.' — '.$f->descricao,
          'detalhe' => trim(($f->marca_modelo ?? '').($f->mantenedor_id ? '' : ' · avulso'), ' ·'), 'marcado' => in_array($f->id, $ferramentasMarcadas, true)])->all(),
      ])
      <small class="suave">Aparecem os equipamentos avulsos e os que já estão com ele. Desmarcar devolve o equipamento (fica avulso).</small>
    </div>

    <div class="campos">
      @include('partials.campo-unidade', ['registro' => $m])
      <label>Matrícula <input name="matricula" maxlength="20" value="{{ old('matricula', $m->matricula) }}"></label>
      <label>Centro de custo
        <select name="centro_custo" data-busca><option value="">—</option>
          @foreach ($centros as $c)<option value="{{ $c->codigo() }}" @selected(old('centro_custo', trim((string) $m->centro_custo)) === $c->codigo())>{{ $c->codigo() }} — {{ $c->descricao() }}</option>@endforeach
        </select></label>
      <label>Calendário
        <select name="calendario_id" data-busca="Buscar calendário…">
          <option value="">— escolha —</option>
          @foreach ($calendarios as $c)<option value="{{ $c->id }}" @selected((int) old('calendario_id', $m->calendario_id) === $c->id)>{{ $c->nome }} {{ $c->ano }} · {{ $c->rotuloUnidade() }}</option>@endforeach
        </select>
        <small>Obrigatório para ativar: dias úteis, feriados e pontes (Administração › Calendários).</small></label>
      <label>Intervalo (min) <input type="number" name="intervalo_min" min="0" max="600" required value="{{ old('intervalo_min', $m->intervalo_min) }}"></label>
    </div>
    @include('partials.turnos-semana', ['registro' => $m, 'rotuloHoras' => 'Horas de trabalho',
      'ajuda' => 'Turno 1 obrigatório no dia marcado; turnos 2 e 3 só se o mantenedor trabalhar neles. Termina antes de começar = vira o dia (ex.: turno das 22:00 às 06:00). Dentro dos turnos = hora NORMAL; fora deles = hora EXTRA. O intervalo (almoço) acima sai das horas disponíveis do dia.'])
    <fieldset class="opcoes-linha">
      <legend>Situação</legend>
      <label class="opcao"><input type="radio" name="ativo" value="1" @checked(old('ativo', (int) $m->ativo) == 1)> <span>Ativo</span></label>
      <label class="opcao"><input type="radio" name="ativo" value="0" @checked(old('ativo', (int) $m->ativo) == 0)> <span>Inativo</span></label>
    </fieldset>
    <div class="acoes">
      <button class="botao" type="submit">Salvar</button>
      <a class="botao secundario" href="{{ route('os.cadastros.mantenedores') }}">Cancelar</a>
    </div>
  </form>
</section>
<script>
  // Ao escolher o usuário, mostra os dados que vêm do login.
  document.getElementById('mant-usuario').addEventListener('change', function () {
    var o = this.selectedOptions[0], d = o ? o.dataset : {};
    var bloco = document.getElementById('mant-dados');
    bloco.hidden = !this.value;
    document.getElementById('mant-email').textContent = d.email || '—';
    document.getElementById('mant-depto').textContent = d.depto || '—';
    document.getElementById('mant-cargo').textContent = d.cargo || '—';
    document.getElementById('mant-cc').textContent = d.cc || '—';
    var av = document.getElementById('mant-avatar');
    av.textContent = '';
    if (d.foto) { var img = document.createElement('img'); img.src = d.foto; img.alt = ''; av.appendChild(img); } else { av.textContent = d.iniciais || ''; }
  });
</script>
@endsection
