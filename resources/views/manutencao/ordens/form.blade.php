@extends('layouts.portal')

@section('titulo', 'Editar '.$os->numeroFormatado())

@use('App\Models\Manutencao\SolicitacaoServico')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Ordens de serviço › <a href="{{ route('os.ordens.index') }}">OS</a> › <a href="{{ route('os.ordens.show', $os) }}">{{ $os->numeroFormatado() }}</a></p>
  <h1>Editar {{ $os->numeroFormatado() }}</h1>
  <p class="suave">Todos os campos da OS podem ser alterados; cada alteração fica no histórico.</p>
  @include('manutencao._erros')

  <form method="post" class="formulario-vertical" action="{{ route('os.ordens.update', $os) }}">
    @csrf @method('put')
    <label>Título resumido <input name="titulo" maxlength="120" value="{{ old('titulo', $os->titulo) }}"></label>
    <div class="campos">
      <label>Tipo
        <select name="tipo" required>@foreach (SolicitacaoServico::TIPOS as $v => $r)<option value="{{ $v }}" @selected(old('tipo', $os->tipo) === $v)>{{ $r }}</option>@endforeach</select>
      </label>
      <label>Área executante
        <select name="area_executante_id" required>
          @foreach ($areas as $a)<option value="{{ $a->id }}" @selected((int) old('area_executante_id', $os->area_executante_id) === $a->id)>{{ $a->nome }} ({{ config('oee.unidades')[$a->unidade] ?? $a->unidade }})</option>@endforeach
        </select>
      </label>
      <label>Responsável
        <select name="responsavel_id"><option value="">Ninguém</option>
          @foreach ($mantenedores as $m)<option value="{{ $m->id }}" @selected((int) old('responsavel_id', $os->responsavel_id) === $m->id)>{{ $m->nome }}</option>@endforeach
        </select>
      </label>
      <label>Máquina / equipamento
        <select name="maquina_id"><option value="">Sem máquina</option>
          @foreach ($maquinas as $m)<option value="{{ $m->id }}" @selected((int) old('maquina_id', $os->maquina_id) === $m->id)>{{ $m->codigo }} — {{ $m->nome }}</option>@endforeach
        </select>
      </label>
      <label>Centro de custo
        <select name="centro_custo"><option value="">—</option>
          @foreach ($centros as $c)<option value="{{ $c->codigo() }}" @selected(old('centro_custo', trim((string) $os->centro_custo)) === $c->codigo())>{{ $c->codigo() }} — {{ $c->descricao() }}</option>@endforeach
        </select>
      </label>
      <label>C.C. solicitante <input name="centro_custo_solicitante" maxlength="120" value="{{ old('centro_custo_solicitante', $os->centro_custo_solicitante) }}"></label>
      <label>Prioridade
        <select name="prioridade" required>@foreach (SolicitacaoServico::PRIORIDADES as $v => $r)<option value="{{ $v }}" @selected(old('prioridade', $os->prioridade) === $v)>{{ $r }}</option>@endforeach</select>
      </label>
      <label>Prevista para <input type="date" name="prevista_para" value="{{ old('prevista_para', $os->prevista_para?->format('Y-m-d')) }}"></label>
    </div>
    <label class="opcao"><input type="checkbox" name="maquina_parada" value="1" @checked(old('maquina_parada', $os->maquina_parada))> <span><strong>Máquina parada</strong></span></label>
    <label>Descrição <textarea name="descricao" rows="4" maxlength="2000" required>{{ old('descricao', $os->descricao) }}</textarea></label>
    <label>Serviço realizado <textarea name="solucao" rows="4" maxlength="2000">{{ old('solucao', $os->solucao) }}</textarea></label>
    <div class="acoes">
      <button class="botao" type="submit">Salvar</button>
      <a class="botao secundario" href="{{ route('os.ordens.show', $os) }}">Cancelar</a>
    </div>
  </form>
</section>
@endsection
