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
      <label>Dia
        <input type="date" name="dia" required value="{{ old('dia', $dia) }}">
      </label>
      <label>Horário inicial
        @php($travado = $proximo['travado'] ?? false)
        <input type="time" name="hora_inicio" step="1" required data-hora-inicio
               value="{{ old('hora_inicio', $travado && ! $apontamento->exists ? $proximo['hora'] : ($horaInicio ?? now(config('oee.fuso_exibicao'))->format('H:i:00'))) }}"
               @readonly($travado)>
        <small data-ajuda-inicio @class(['texto-alerta' => $proximo['completo'] ?? false])>{{ $proximo['mensagem'] ?? 'Primeiro apontamento do dia: horário livre.' }}</small>
      </label>
      <label>Horário final
        <input type="time" name="hora_fim" step="1" value="{{ old('hora_fim', $horaFim) }}">
        <small>Até, no mesmo dia (no máximo 23:59:59). Deixe vazio se a máquina ainda está nesse estado.</small>
      </label>
      @include('partials.campo-op', ['op' => $apontamento->ordemProducao,
        'ajuda' => 'Busque pela OP ou pelo produto. Vem com a OP atual da máquina; "Sem OP" desvincula a OP da máquina. Obrigatória quando a legenda exige OP.'])
      <label>Referência
        <input name="referencia" maxlength="40" value="{{ old('referencia', $apontamento->referencia) }}" placeholder="Ex.: OP 509859">
        <small>Aparece na dica da barra na timeline (ordem de produção, lote ou produto).</small>
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
      @if ($apontamento->atualizado_por_id)
        <small class="suave auditoria-linha">Alterado por {{ $apontamento->atualizadoPor?->nome ?? '-' }} em @dataHora($apontamento->atualizado_em)</small>
      @endif
      @if ($apontamento->automaticoIot())
        <small class="suave auditoria-linha"><span class="etiqueta selo-iot">IoT</span> {{ $apontamento->textoOrigem() }}</small>
      @endif
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

    // Horário inicial: se a máquina já tem apontamento no dia, começa 1 segundo depois do fim do último (travado);
    // primeiro do dia = livre. Recalcula ao trocar a máquina ou o dia.
    var campoDia = document.querySelector('input[name="dia"]');
    var horaInicio = document.querySelector('[data-hora-inicio]');
    var ajudaInicio = document.querySelector('[data-ajuda-inicio]');
    var urlProximo = @json(route('apontamentos.proximo-inicio'));
    var editando = @json($apontamento->exists ? $apontamento->id : null);
    function atualizarInicio() {
      var maq = document.querySelector('select[name="maquina_id"]');
      if (!maq || !maq.value || !campoDia.value) return;
      var q = '?maquina_id=' + encodeURIComponent(maq.value) + '&dia=' + encodeURIComponent(campoDia.value) + (editando ? '&apontamento=' + editando : '');
      fetch(urlProximo + q, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (!d) return;
          horaInicio.readOnly = d.travado;
          if (d.travado && d.hora) horaInicio.value = d.hora;
          ajudaInicio.textContent = d.mensagem;
          ajudaInicio.classList.toggle('texto-alerta', !!d.completo);
        });
    }
    campoDia.addEventListener('change', atualizarInicio);
    document.querySelector('select[name="maquina_id"]').addEventListener('change', atualizarInicio);

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
