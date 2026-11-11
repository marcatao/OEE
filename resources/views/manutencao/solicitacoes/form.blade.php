@extends('layouts.portal')

@section('titulo', $ss->exists ? 'Editar '.$ss->numeroFormatado() : 'Nova solicitação de serviço')

@use('App\Models\Manutencao\SolicitacaoServico')

@section('conteudo')
@php($autor = $ss->exists ? $ss->solicitante : auth()->user())
<section class="cartao">
  <p class="trilha">Ordens de serviço › <a href="{{ route('os.solicitacoes.index') }}">Solicitações</a></p>
  <div class="titulo-com-acao">
    <h1>{{ $ss->exists ? 'Editar '.$ss->numeroFormatado() : 'Nova solicitação de serviço' }}</h1>
    <span class="ss-autor">@if ($autor)@include('partials.avatar', ['usuario' => $autor])@endif
      <span><small class="suave">Solicitante</small><br><strong>{{ $autor?->nome }}</strong></span></span>
  </div>
  @include('manutencao._erros')

  <form method="post" class="formulario-vertical ss-form" action="{{ $ss->exists ? route('os.solicitacoes.update', $ss) : route('os.solicitacoes.store') }}">
    @csrf
    @if ($ss->exists) @method('put') @endif
    <input type="hidden" name="apontamento_estado_id" value="{{ old('apontamento_estado_id', $ss->apontamento_estado_id) }}">
    <input type="hidden" name="ordem_producao_id" value="{{ old('ordem_producao_id', $ss->ordem_producao_id) }}">

    <fieldset>
      <legend>O que precisa</legend>
      <div class="campos ss-campos">
        <label class="largo">Título resumido
          <input name="titulo" required maxlength="120" value="{{ old('titulo', $ss->titulo) }}" placeholder="Ex.: Vazamento de óleo no cilindro da injetora" autofocus>
        </label>
        <label>Tipo
          <select name="tipo" required>
            @foreach (SolicitacaoServico::TIPOS as $valor => $rotulo)<option value="{{ $valor }}" @selected(old('tipo', $ss->tipo) === $valor)>{{ $rotulo }}</option>@endforeach
          </select>
        </label>
        <label>Prioridade
          <select name="prioridade" required>
            @foreach (SolicitacaoServico::PRIORIDADES as $valor => $rotulo)<option value="{{ $valor }}" @selected(old('prioridade', $ss->prioridade) === $valor)>{{ $rotulo }}</option>@endforeach
          </select>
        </label>
        <label>Área executante
          <select name="area_executante_id" required>
            <option value="">Escolha…</option>
            @foreach ($areas as $a)
              <option value="{{ $a->id }}" @selected((int) old('area_executante_id', $ss->area_executante_id) === $a->id)>{{ $a->nome }} — {{ config('oee.unidades')[$a->unidade] ?? $a->unidade }}</option>
            @endforeach
          </select>
        </label>
        <label class="opcao ss-parada"><input type="checkbox" name="maquina_parada" value="1" @checked(old('maquina_parada', $ss->maquina_parada))>
          <span><strong>Máquina parada?</strong><small>marque se o problema parou a máquina</small></span></label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Onde</legend>
      <div class="campos ss-campos alinhar-topo">
        <label class="largo-2">Máquina / equipamento
          <select name="maquina_id" id="ss-maquina">
            <option value="" data-cc="">Sem máquina (serviço geral)</option>
            @foreach ($maquinas as $m)
              <option value="{{ $m->id }}" data-cc="{{ trim((string) $m->centro_custo) }}" @selected((int) old('maquina_id', $ss->maquina_id) === $m->id)>{{ $m->codigo }} — {{ $m->nome }}</option>
            @endforeach
          </select>
        </label>
        <label class="largo-2">Centro de custo
          <input name="centro_custo" id="ss-cc" list="ss-lista-cc" maxlength="20" autocomplete="off"
                 value="{{ old('centro_custo', trim((string) $ss->centro_custo) ?: $ccUsuario) }}" placeholder="Digite o código ou o nome e escolha">
          <small id="ss-cc-info" class="suave"></small>
        </label>
      </div>
      <datalist id="ss-lista-cc">
        @foreach ($centros as $c)<option value="{{ $c->codigo() }}">{{ $c->codigo() }} — {{ $c->descricao() }}</option>@endforeach
      </datalist>
    </fieldset>

    <fieldset>
      <legend>Descrição do problema ou do serviço</legend>
      <textarea name="descricao" rows="6" maxlength="2000" required id="ss-descricao" placeholder="Conte o que está acontecendo, desde quando, o que já foi feito…">{{ old('descricao', $ss->descricao) }}</textarea>
      @if ($sugestoes->isNotEmpty())
        <div class="ss-sugestoes"><small class="suave">Sugestões:</small>
          @foreach ($sugestoes as $s)<button type="button" class="ss-chip" data-sugestao="{{ $s }}">{{ $s }}</button>@endforeach
        </div>
      @endif
    </fieldset>

    <div class="acoes">
      <button class="botao" type="submit">{{ $ss->exists ? 'Salvar' : 'Enviar solicitação' }}</button>
      <a class="botao secundario" href="{{ $ss->exists ? route('os.solicitacoes.show', $ss) : route('os.solicitacoes.index') }}">Cancelar</a>
    </div>
  </form>
</section>
<script>
  (function () {
    var ccUsuario = @json((string) $ccUsuario);
    var descricoes = {};
    document.querySelectorAll('#ss-lista-cc option').forEach(function (o) { descricoes[o.value] = o.textContent; });
    var maquina = document.getElementById('ss-maquina'), cc = document.getElementById('ss-cc'), info = document.getElementById('ss-cc-info');

    function mostrarDescricao(origem) {
      var d = descricoes[cc.value.trim()];
      info.textContent = d ? d + (origem ? ' · ' + origem : '') : (cc.value.trim() ? 'Código não encontrado: escolha da lista.' : 'Obrigatório: busque pelo código ou nome.');
    }
    // Máquina com CC: usa o dela e trava. Sem máquina ou máquina sem CC: o do solicitante (se tiver) e libera para escolher.
    function aplicarMaquina(inicio) {
      var ccMaq = maquina.selectedOptions[0] ? maquina.selectedOptions[0].dataset.cc : '';
      if (ccMaq) {
        cc.value = ccMaq; cc.readOnly = true; cc.required = false;
        mostrarDescricao('do cadastro da máquina');
      } else {
        if (cc.readOnly || !inicio) { cc.value = ccUsuario || (inicio ? cc.value : ''); }
        cc.readOnly = false; cc.required = true;
        mostrarDescricao(cc.value && cc.value === ccUsuario ? 'do seu cadastro' : '');
      }
    }
    maquina.addEventListener('change', function () { aplicarMaquina(false); });
    cc.addEventListener('input', function () { mostrarDescricao(''); });
    aplicarMaquina(true);

    document.querySelectorAll('[data-sugestao]').forEach(function (b) {
      b.addEventListener('click', function () {
        var d = document.getElementById('ss-descricao');
        d.value = d.value ? d.value.replace(/\s*$/, '') + '\n' + b.dataset.sugestao : b.dataset.sugestao;
        d.focus();
      });
    });
  })();
</script>
@endsection
