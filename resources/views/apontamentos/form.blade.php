@extends('layouts.portal')

@section('titulo', $apontamento->exists ? 'Editar apontamento' : 'Novo apontamento')

@section('conteudo')
@php($E = \App\Models\ApontamentoEstado::class)
<section class="cartao">
  <p class="trilha">Produção ›
    @if ($voltar === 'timeline')<a href="{{ route('timeline') }}">Timeline</a>@else<a href="{{ route('apontamentos.index') }}">Apontamento real</a>@endif</p>
  <h1>{{ $apontamento->exists ? 'Editar apontamento' : 'Novo apontamento' }}</h1>

  @if ($errors->any())<p class="aviso erro" role="alert">{{ $errors->first() }}</p>@endif

  <form method="post" class="formulario-vertical"
        action="{{ $apontamento->exists ? route('apontamentos.update', $apontamento) : route('apontamentos.store') }}">
    @csrf
    @if ($apontamento->exists) @method('put') @endif
    <input type="hidden" name="voltar" value="{{ $voltar }}">

    <div class="campos">
      <label>Máquina
        <select name="maquina_id" required>
          <option value="">Escolha…</option>
          @foreach ($maquinas as $m)
            <option value="{{ $m->id }}" data-op="{{ $m->ordemProducao?->numero }}" data-op-texto="{{ $m->ordemProducao ? $m->ordemProducao->textoBusca($produtosOp[rtrim($m->ordemProducao->c2_produto)] ?? null) : '' }}" @selected((int) old('maquina_id', $apontamento->maquina_id) === $m->id)>{{ $m->nome }} ({{ $m->codigo }})@if ($m->ordemProducao) · OP {{ $m->ordemProducao->numero }}@endif</option>
          @endforeach
        </select>
      </label>
      <label>Legenda
        <select name="legenda_id" required>
          <option value="">Escolha…</option>
          @foreach ($legendas as $l)
            <option value="{{ $l->id }}" data-exige-op="{{ $l->exige_op ? 1 : 0 }}" @selected((int) old('legenda_id', $apontamento->legenda_id) === $l->id)>{{ $l->codigo }} — {{ $l->descricao }}@if ($l->exige_op) (exige OP)@endif</option>
          @endforeach
        </select>
      </label>
      <label>Horário inicial
        <input type="datetime-local" name="inicio" required value="{{ old('inicio', $E::paraHoraLocal($apontamento->inicio)) }}">
      </label>
      <label>Horário final
        <input type="datetime-local" name="fim" value="{{ old('fim', $E::paraHoraLocal($apontamento->fim)) }}">
        <small>Deixe vazio se a máquina ainda está nesse estado.</small>
      </label>
      @include('partials.campo-op', ['op' => $apontamento->ordemProducao,
        'ajuda' => 'Busque pela OP ou pelo produto. Vem com a OP atual da máquina; "Sem OP" desvincula a OP da máquina. Obrigatória quando a legenda exige OP.'])
      <label>Referência
        <input name="referencia" maxlength="40" value="{{ old('referencia', $apontamento->referencia) }}" placeholder="Ex.: OP 509859">
        <small>Aparece dentro da barra na timeline (ordem de produção, lote ou produto).</small>
      </label>
    </div>
    <label>Observação
      <textarea name="observacao" maxlength="500" rows="3">{{ old('observacao', $apontamento->observacao) }}</textarea>
    </label>

    <div class="acoes">
      <button class="botao" type="submit">Salvar</button>
      <a class="botao secundario" href="{{ $voltar === 'timeline' ? route('timeline') : route('apontamentos.index') }}">Cancelar</a>
    </div>
  </form>

  @if ($apontamento->exists)
    <form method="post" action="{{ route('apontamentos.destroy', $apontamento) }}" style="margin-top:1.5rem"
          onsubmit="return confirm('Excluir este apontamento?')">
      @csrf @method('delete')
      <input type="hidden" name="voltar" value="{{ $voltar }}">
      <button class="botao secundario pequeno" type="submit">@include('layouts.partials.icone', ['nome' => 'lixeira']) Excluir apontamento</button>
      <small class="suave">Registrado por {{ $apontamento->criadoPor?->nome ?? '-' }} em @dataHora($apontamento->criado_em)</small>
    </form>
  @endif
</section>
<script>
  (function () {
    var legenda = document.querySelector('select[name="legenda_id"]');
    var op = document.querySelector('select[name="ordem_producao"]');
    var ajuda = document.querySelector('[data-ajuda-op]');
    if (!legenda || !op) return;

    // Legenda que exige OP: destaca o aviso no campo da OP (o servidor também confere ao gravar).
    var aplicar = function () {
      var opcao = legenda.options[legenda.selectedIndex];
      if (ajuda) ajuda.classList.toggle('texto-alerta', !!(opcao && opcao.dataset.exigeOp === '1') && op.value === '');
    };
    legenda.addEventListener('change', aplicar);
    op.addEventListener('change', aplicar);
    aplicar();

    // Apontamento novo: ao trocar a máquina, a OP vira a OP atual dela (se estava vazia ou com a da máquina anterior).
    var maquina = document.querySelector('select[name="maquina_id"]');
    var dado = function (nome) { var o = maquina.options[maquina.selectedIndex]; return o ? (o.dataset[nome] || '') : ''; };
    var opDaMaquina = maquina ? dado('op') : '';
    if (maquina && !{{ $apontamento->exists ? 'true' : 'false' }}) {
      maquina.addEventListener('change', function () {
        var nova = dado('op');
        if (op.value === '' || op.value === opDaMaquina) {
          if (nova && !Array.prototype.some.call(op.options, function (o) { return o.value === nova; })) op.add(new Option(dado('opTexto'), nova));
          op.value = nova;
          op.dispatchEvent(new Event('change', { bubbles: true }));
        }
        opDaMaquina = nova;
      });
    }
  })();
</script>
@endsection
