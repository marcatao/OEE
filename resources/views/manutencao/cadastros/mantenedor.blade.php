@extends('layouts.portal')

@section('titulo', $m->exists ? $m->nome : 'Novo mantenedor')

@use('App\Models\Manutencao\Mantenedor')

@section('conteudo')
@php($dias = array_map('intval', old('dias', str_split((string) $m->dias_semana))))
<section class="cartao">
  <p class="trilha">Ordens de serviço › <a href="{{ route('os.cadastros.mantenedores') }}">Mantenedores</a></p>
  <h1>{{ $m->exists ? $m->nome : 'Novo mantenedor' }}</h1>
  @include('manutencao._erros')
  <form method="post" class="formulario-vertical" action="{{ $m->exists ? route('os.cadastros.mantenedores.update', $m) : route('os.cadastros.mantenedores.store') }}">
    @csrf
    @if ($m->exists) @method('put') @endif
    <div class="campos">
      @include('partials.campo-unidade', ['registro' => $m])
      <label>Nome <input name="nome" required maxlength="120" value="{{ old('nome', $m->nome) }}"></label>
      <label>Matrícula <input name="matricula" maxlength="20" value="{{ old('matricula', $m->matricula) }}"></label>
      <label>Login no portal
        <select name="usuario_id"><option value="">Sem login (só lançado por outros)</option>
          @foreach ($usuarios as $u)<option value="{{ $u->id }}" @selected((int) old('usuario_id', $m->usuario_id) === $u->id)>{{ $u->nome }} ({{ $u->email }})</option>@endforeach
        </select></label>
      <label>Área executante
        <select name="area_executante_id"><option value="">—</option>
          @foreach ($areas as $a)<option value="{{ $a->id }}" @selected((int) old('area_executante_id', $m->area_executante_id) === $a->id)>{{ $a->nome }}</option>@endforeach
        </select></label>
      <label>Centro de custo
        <select name="centro_custo"><option value="">—</option>
          @foreach ($centros as $c)<option value="{{ $c->codigo() }}" @selected(old('centro_custo', trim((string) $m->centro_custo)) === $c->codigo())>{{ $c->codigo() }} — {{ $c->descricao() }}</option>@endforeach
        </select></label>
      <label>Início da jornada <input type="time" name="jornada_inicio" required value="{{ old('jornada_inicio', $m->jornada_inicio) }}"></label>
      <label>Fim da jornada <input type="time" name="jornada_fim" required value="{{ old('jornada_fim', $m->jornada_fim) }}"><small>Antes do início = jornada noturna (vira o dia).</small></label>
      <label>Intervalo (min) <input type="number" name="intervalo_min" min="0" max="600" required value="{{ old('intervalo_min', $m->intervalo_min) }}"></label>
    </div>
    <fieldset class="opcoes-linha">
      <legend>Dias de trabalho</legend>
      @foreach (Mantenedor::DIAS_SEMANA as $n => $nome)
        <label class="opcao"><input type="checkbox" name="dias[]" value="{{ $n }}" @checked(in_array((int) $n, $dias, true))> <span>{{ $nome }}</span></label>
      @endforeach
    </fieldset>
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
@endsection
