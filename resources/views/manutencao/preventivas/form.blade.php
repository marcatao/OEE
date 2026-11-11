@extends('layouts.portal')

@section('titulo', $plano->exists ? 'Preventiva '.$plano->codigo : 'Nova preventiva')

@use('App\Models\Manutencao\PlanoPreventiva')
@use('App\Models\Manutencao\SolicitacaoServico')

@section('conteudo')
@php($meses = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'])
@php($marcados = old('meses', collect(range(1, 12))->filter(fn ($m) => ($plano->meses[$m - 1] ?? '0') === '1')->all()))
<section class="cartao">
  <p class="trilha">Ordens de serviço › <a href="{{ route('os.preventivas.index') }}">Preventivas</a></p>
  <h1>{{ $plano->exists ? $plano->codigo.' — '.$plano->descricao : 'Nova preventiva' }}</h1>
  @include('manutencao._erros')

  <form method="post" class="formulario-vertical" action="{{ $plano->exists ? route('os.preventivas.update', $plano) : route('os.preventivas.store') }}">
    @csrf
    @if ($plano->exists) @method('put') @endif
    <div class="campos">
      @include('partials.campo-unidade', ['registro' => $plano])
      <label>Código <input name="codigo" required maxlength="20" value="{{ old('codigo', $plano->codigo) }}" style="text-transform:uppercase"></label>
      <label>Descrição <input name="descricao" required maxlength="200" value="{{ old('descricao', $plano->descricao) }}"></label>
      <label>Máquina
        <select name="maquina_id"><option value="">Sem máquina</option>
          @foreach ($maquinas as $m)<option value="{{ $m->id }}" @selected((int) old('maquina_id', $plano->maquina_id) === $m->id)>{{ $m->codigo }} — {{ $m->nome }}</option>@endforeach
        </select></label>
      <label>Área executante
        <select name="area_executante_id" required><option value="">Escolha…</option>
          @foreach ($areas as $a)<option value="{{ $a->id }}" @selected((int) old('area_executante_id', $plano->area_executante_id) === $a->id)>{{ $a->nome }}</option>@endforeach
        </select></label>
      <label>Responsável
        <select name="responsavel_id"><option value="">Definir na OS</option>
          @foreach ($mantenedores as $m)<option value="{{ $m->id }}" @selected((int) old('responsavel_id', $plano->responsavel_id) === $m->id)>{{ $m->nome }}</option>@endforeach
        </select></label>
      <label>Prioridade
        <select name="prioridade">@foreach (SolicitacaoServico::PRIORIDADES as $v => $r)<option value="{{ $v }}" @selected(old('prioridade', $plano->prioridade) === $v)>{{ $r }}</option>@endforeach</select></label>
      <label>Periodicidade
        <select name="periodicidade">@foreach (PlanoPreventiva::PERIODICIDADES as $v => $r)<option value="{{ $v }}" @selected(old('periodicidade', $plano->periodicidade) === $v)>{{ $r }}</option>@endforeach</select></label>
      <label>Dia do mês <input type="number" name="dia_mes" min="1" max="28" value="{{ old('dia_mes', $plano->dia_mes) }}"><small>Para "meses marcados".</small></label>
      <label>Próxima data <input type="date" name="proxima_em" value="{{ old('proxima_em', $plano->proxima_em?->format('Y-m-d')) }}"><small>Obrigatória em semanal/quinzenal; em branco, calculada pelos meses.</small></label>
      <label>Gerar a OS com antecedência (dias) <input type="number" name="antecedencia_dias" min="0" max="90" value="{{ old('antecedencia_dias', $plano->antecedencia_dias) }}"></label>
      <label>Duração prevista (min) <input type="number" name="duracao_prevista_min" min="1" value="{{ old('duracao_prevista_min', $plano->duracao_prevista_min) }}"></label>
    </div>

    <fieldset>
      <legend>Meses da PAMP (todos = mensal; 4 = trimestral; 1 = anual)</legend>
      <div class="grade-opcoes">
        @foreach ($meses as $i => $nome)
          <label class="opcao"><input type="checkbox" name="meses[]" value="{{ $i + 1 }}" @checked(in_array($i + 1, array_map('intval', (array) $marcados), true))> <span>{{ $nome }}</span></label>
        @endforeach
      </div>
    </fieldset>

    <fieldset>
      <legend>Checklist (um item por linha; apague o texto para desativar)</legend>
      @foreach ($itens as $i => $item)
        <input type="hidden" name="itens[{{ $i }}][id]" value="{{ $item->id }}">
        <input name="itens[{{ $i }}][descricao]" maxlength="300" value="{{ $item->ativo ? $item->descricao : '' }}" placeholder="{{ $item->ativo ? '' : 'desativado: '.$item->descricao }}" style="width:100%;margin-bottom:.4rem">
      @endforeach
      @for ($i = $itens->count(); $i < $itens->count() + 5; $i++)
        <input name="itens[{{ $i }}][descricao]" maxlength="300" placeholder="Novo item do checklist" style="width:100%;margin-bottom:.4rem">
      @endfor
    </fieldset>

    <fieldset class="opcoes-linha">
      <legend>Situação</legend>
      <label class="opcao"><input type="radio" name="ativo" value="1" @checked(old('ativo', (int) $plano->ativo) == 1)> <span>Ativa</span></label>
      <label class="opcao"><input type="radio" name="ativo" value="0" @checked(old('ativo', (int) $plano->ativo) == 0)> <span>Inativa <small>não gera OS novas</small></span></label>
    </fieldset>
    <div class="acoes">
      <button class="botao" type="submit">Salvar</button>
      <a class="botao secundario" href="{{ route('os.preventivas.index') }}">Cancelar</a>
    </div>
  </form>
</section>
@endsection
