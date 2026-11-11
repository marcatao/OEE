@extends('layouts.portal')

@section('titulo', $ss->exists ? 'Editar '.$ss->numeroFormatado() : 'Nova solicitação de serviço')

@use('App\Models\Manutencao\SolicitacaoServico')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Ordens de serviço › <a href="{{ route('os.solicitacoes.index') }}">Solicitações</a></p>
  <h1>{{ $ss->exists ? 'Editar '.$ss->numeroFormatado() : 'Nova solicitação de serviço' }}</h1>
  <p class="suave">Solicitante: <strong>{{ $ss->exists ? $ss->solicitante?->nome : auth()->user()->nome }}</strong>.
    O número da SS é gerado ao enviar.</p>
  @include('manutencao._erros')

  <form method="post" class="formulario-vertical" action="{{ $ss->exists ? route('os.solicitacoes.update', $ss) : route('os.solicitacoes.store') }}">
    @csrf
    @if ($ss->exists) @method('put') @endif
    <input type="hidden" name="apontamento_estado_id" value="{{ old('apontamento_estado_id', $ss->apontamento_estado_id) }}">
    <input type="hidden" name="ordem_producao_id" value="{{ old('ordem_producao_id', $ss->ordem_producao_id) }}">

    <div class="campos">
      <label>Tipo
        <select name="tipo" required>
          @foreach (SolicitacaoServico::TIPOS as $valor => $rotulo)
            <option value="{{ $valor }}" @selected(old('tipo', $ss->tipo) === $valor)>{{ $rotulo }}</option>
          @endforeach
        </select>
      </label>
      <label>Área executante
        <select name="area_executante_id" required>
          <option value="">Escolha quem vai fazer o serviço…</option>
          @foreach ($areas as $a)
            <option value="{{ $a->id }}" @selected((int) old('area_executante_id', $ss->area_executante_id) === $a->id)>{{ $a->nome }} ({{ config('oee.unidades')[$a->unidade] ?? $a->unidade }})</option>
          @endforeach
        </select>
        <small>Define se vai para a Manutenção de Produção ou de Tecnologia.</small>
      </label>
      <label>Máquina / equipamento
        <select name="maquina_id" id="ss-maquina">
          <option value="">Sem máquina (serviço geral)</option>
          @foreach ($maquinas as $m)
            <option value="{{ $m->id }}" data-cc="{{ trim((string) $m->centro_custo) }}" @selected((int) old('maquina_id', $ss->maquina_id) === $m->id)>{{ $m->codigo }} — {{ $m->nome }}</option>
          @endforeach
        </select>
      </label>
      <label>Centro de custo
        <select name="centro_custo" id="ss-cc">
          <option value="">Da máquina</option>
          @foreach ($centros as $c)
            <option value="{{ $c->codigo() }}" @selected(old('centro_custo', trim((string) $ss->centro_custo)) === $c->codigo())>{{ $c->codigo() }} — {{ $c->descricao() }}</option>
          @endforeach
        </select>
      </label>
      <label>Prioridade
        <select name="prioridade" required>
          @foreach (SolicitacaoServico::PRIORIDADES as $valor => $rotulo)
            <option value="{{ $valor }}" @selected(old('prioridade', $ss->prioridade) === $valor)>{{ $rotulo }}</option>
          @endforeach
        </select>
      </label>
    </div>
    <label class="opcao"><input type="checkbox" name="maquina_parada" value="1" @checked(old('maquina_parada', $ss->maquina_parada))> <span><strong>Máquina parada?</strong> <small>marque se a máquina está parada por causa deste problema</small></span></label>

    <label>Descrição do problema ou do serviço
      <textarea name="descricao" rows="5" maxlength="2000" required list="ss-sugestoes" id="ss-descricao">{{ old('descricao', $ss->descricao) }}</textarea>
    </label>
    @if ($sugestoes->isNotEmpty())
      <p class="suave">Sugestões (clique para usar):
        @foreach ($sugestoes as $s)
          <button type="button" class="botao pequeno secundario" data-sugestao="{{ $s }}">{{ $s }}</button>
        @endforeach
      </p>
    @endif

    <div class="acoes">
      <button class="botao" type="submit">{{ $ss->exists ? 'Salvar' : 'Enviar solicitação' }}</button>
      <a class="botao secundario" href="{{ $ss->exists ? route('os.solicitacoes.show', $ss) : route('os.solicitacoes.index') }}">Cancelar</a>
    </div>
  </form>
</section>
<script>
  // Sugestão: acrescenta o texto na descrição. Máquina: sugere o centro de custo dela.
  document.querySelectorAll('[data-sugestao]').forEach(function (b) {
    b.addEventListener('click', function () {
      var d = document.getElementById('ss-descricao');
      d.value = d.value ? d.value.replace(/\s*$/, '') + '\n' + b.dataset.sugestao : b.dataset.sugestao;
      d.focus();
    });
  });
  document.getElementById('ss-maquina').addEventListener('change', function () {
    var cc = this.selectedOptions[0] ? this.selectedOptions[0].dataset.cc : '';
    var sel = document.getElementById('ss-cc');
    if (cc && sel.querySelector('option[value="' + cc + '"]')) sel.value = cc;
  });
</script>
@endsection
