@extends('layouts.portal')

@section('titulo', $f->exists ? 'Ferramenta '.$f->codigo : 'Nova ferramenta')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Ordens de serviço › <a href="{{ route('os.cadastros.ferramentas') }}">Ferramentas</a></p>
  <h1>{{ $f->exists ? $f->codigo.' — '.$f->descricao : 'Nova ferramenta' }}</h1>
  @include('manutencao._erros')
  <form method="post" class="formulario-vertical" action="{{ $f->exists ? route('os.cadastros.ferramentas.update', $f) : route('os.cadastros.ferramentas.store') }}">
    @csrf
    @if ($f->exists) @method('put') @endif
    <div class="campos">
      @include('partials.campo-unidade', ['registro' => $f])
      <label>Código / patrimônio <input name="codigo" required maxlength="30" value="{{ old('codigo', $f->codigo) }}" style="text-transform:uppercase"></label>
      <label class="largo">Descrição <input name="descricao" required maxlength="150" value="{{ old('descricao', $f->descricao) }}" placeholder="Ex.: Multímetro digital"></label>
      <label>Marca / modelo <input name="marca_modelo" maxlength="100" value="{{ old('marca_modelo', $f->marca_modelo) }}"></label>
      <label>Nº de série <input name="numero_serie" maxlength="60" value="{{ old('numero_serie', $f->numero_serie) }}"></label>
      <label>Está com
        <select name="mantenedor_id" data-busca="Buscar mantenedor…">
          <option value="">Avulsa (sem mantenedor)</option>
          @foreach ($mantenedores as $m)<option value="{{ $m->id }}" @selected((int) old('mantenedor_id', $f->mantenedor_id) === $m->id)>{{ $m->nomeExibicao() }}</option>@endforeach
        </select>
        @if ($f->atribuida_em)<small>Desde @dataHora($f->atribuida_em).</small>@endif
      </label>
      <label class="largo">Observação <input name="observacao" maxlength="500" value="{{ old('observacao', $f->observacao) }}"></label>
    </div>
    <fieldset class="opcoes-linha">
      <legend>Situação</legend>
      <label class="opcao"><input type="radio" name="ativo" value="1" @checked(old('ativo', (int) $f->ativo) == 1)> <span>Ativa</span></label>
      <label class="opcao"><input type="radio" name="ativo" value="0" @checked(old('ativo', (int) $f->ativo) == 0)> <span>Inativa <small>baixada, perdida ou descartada</small></span></label>
    </fieldset>
    <div class="acoes">
      <button class="botao" type="submit">Salvar</button>
      <a class="botao secundario" href="{{ route('os.cadastros.ferramentas') }}">Cancelar</a>
    </div>
  </form>
</section>
@endsection
