@extends('layouts.portal')

@section('titulo', 'Timeline')

@section('conteudo')
@php($u = auth()->user())
<section class="cartao">
  <p class="trilha">Dashboards</p>
  <div class="tl-topo">
    <h1>Timeline</h1>
    @if ($u->pode('apontamentos'))
      <a class="botao" href="{{ route('apontamentos.create', ['voltar' => 'timeline']) }}">@include('layouts.partials.icone', ['nome' => 'mais']) Novo apontamento</a>
    @endif
  </div>

  {{-- Filtros: qualquer mudança atualiza só os dados (sem recarregar a página). --}}
  <form id="tl-filtros" class="tl-filtros" method="get" action="{{ route('timeline') }}"
        data-hoje-de="{{ $hoje->de->format('Y-m-d\TH:i') }}" data-hoje-ate="{{ $hoje->ate->format('Y-m-d\TH:i') }}">
    <div class="tl-periodo">
      <label>De <input type="datetime-local" name="de" value="{{ $filtro->de->format('Y-m-d\TH:i') }}" required></label>
      <label>Até <input type="datetime-local" name="ate" value="{{ $filtro->ate->format('Y-m-d\TH:i') }}" required></label>
      <div class="tl-atalhos" role="group" aria-label="Atalhos de período">
        <button type="button" class="topo-icone" data-dias="-1" title="Um dia antes" aria-label="Um dia antes">@include('layouts.partials.icone', ['nome' => 'anterior'])</button>
        <button type="button" class="botao secundario pequeno" data-hoje>Hoje</button>
        <button type="button" class="topo-icone" data-dias="1" title="Um dia depois" aria-label="Um dia depois">@include('layouts.partials.icone', ['nome' => 'seta'])</button>
        @if ($turnos->isNotEmpty())
          <span class="tl-turnos">
            @foreach ($turnos as $t)
              <button type="button" data-turno-inicio="{{ substr((string) $t->inicio, 0, 5) }}" data-turno-fim="{{ substr((string) $t->fim, 0, 5) }}"
                      title="{{ $t->nome }} ({{ substr((string) $t->inicio, 0, 5) }}–{{ substr((string) $t->fim, 0, 5) }}) no dia do &quot;De&quot;">{{ $t->nome }}</button>
            @endforeach
          </span>
        @endif
      </div>
    </div>

    <div class="tl-selecoes">
      @include('dashboard.timeline._multi', ['campo' => 'plantas', 'rotulo' => 'Plantas', 'itens' => $opcoes['plantas'],
        'marcados' => $filtro->plantas, 'texto' => fn ($p) => $p->nome, 'dados' => fn ($p) => ['planta' => $p->id]])
      @include('dashboard.timeline._multi', ['campo' => 'linhas', 'rotulo' => 'Linhas', 'itens' => $opcoes['linhas'],
        'marcados' => $filtro->linhas, 'texto' => fn ($l) => $l->nome.($l->planta ? ' · '.$l->planta->nome : ''), 'dados' => fn ($l) => ['planta' => $l->planta_id, 'linha' => $l->id]])
      @include('dashboard.timeline._multi', ['campo' => 'maquinas', 'rotulo' => 'Máquinas', 'itens' => $opcoes['maquinas'],
        'marcados' => $filtro->maquinas, 'texto' => fn ($m) => $m->nome.' ('.$m->codigo.')', 'dados' => fn ($m) => ['planta' => $m->linha?->planta_id, 'linha' => $m->linha_id]])

      <label class="tl-salvos">Filtros salvos
        <select id="tl-salvos" aria-label="Filtros salvos">
          <option value="">{{ $escolhido ? 'Personalizado' : '—' }}</option>
          @foreach ($salvos as $s)
            <option value="{{ $s->id }}" @selected($escolhido?->id === $s->id)>{{ $s->nome }}{{ $s->padrao ? ' ★ padrão' : '' }}</option>
          @endforeach
        </select>
      </label>
    </div>
    <noscript><button class="botao" type="submit">Aplicar filtros</button></noscript>
  </form>

  <div class="tl-acoes-filtro">
    <details class="tl-salvar">
      <summary class="botao secundario pequeno">Salvar filtro</summary>
      <form id="tl-salvar" method="post" action="{{ route('timeline.filtros.salvar') }}" class="tl-salvar-form">
        @csrf
        <label>Nome <input name="nome" required maxlength="80" value="{{ $escolhido?->nome }}" placeholder="Ex.: Injetoras — turno A"></label>
        <fieldset>
          <legend>Período</legend>
          <label class="opcao"><input type="radio" name="periodo" value="hoje" @checked(($escolhido?->filtros['periodo'] ?? 'hoje') === 'hoje')>
            <span>Dia atual <small>usa as horas de "De" e "Até" no dia em que a tela for aberta</small></span></label>
          <label class="opcao"><input type="radio" name="periodo" value="fixo" @checked(($escolhido?->filtros['periodo'] ?? '') === 'fixo')>
            <span>Datas fixas <small>sempre o mesmo período escolhido agora</small></span></label>
        </fieldset>
        <label class="opcao"><input type="checkbox" name="padrao" value="1" @checked($escolhido?->padrao)> <span>Abrir a Timeline com este filtro</span></label>
        <button class="botao pequeno" type="submit">Salvar</button>
        <small class="suave">Com o mesmo nome de um filtro já salvo, ele é atualizado.</small>
      </form>
    </details>
    @if ($escolhido)
      <form method="post" action="{{ route('timeline.filtros.excluir', $escolhido) }}" data-pergunta="Excluir o filtro “{{ $escolhido->nome }}”?"
            onsubmit="return confirm(this.dataset.pergunta)">
        @csrf @method('delete')
        <button class="botao secundario pequeno" type="submit">Excluir filtro "{{ $escolhido->nome }}"</button>
      </form>
    @endif
    <span id="tl-carregando" class="suave" hidden>Atualizando…</span>
    <span id="tl-falha" class="erro" hidden>Não foi possível atualizar. Tentando de novo em instantes.</span>
  </div>

  <div id="tl-resultado" aria-live="polite">
    @include('dashboard.timeline._resultado')
  </div>

  @if ($legendas->isNotEmpty())
    <div class="tl-resumo" style="background:none">
      <span class="suave">Legendas:</span>
      @foreach ($legendas as $l)
        <span class="tl-chip"><i class="tl-cor" style="background: {{ $l->cor }}"></i>{{ $l->codigo }} <small class="suave">{{ $l->descricao }}</small></span>
      @endforeach
    </div>
  @endif
</section>

<script>
(function () {
  var form = document.getElementById('tl-filtros');
  var resultado = document.getElementById('tl-resultado');
  var carregando = document.getElementById('tl-carregando');
  var falha = document.getElementById('tl-falha');
  var salvos = document.getElementById('tl-salvos');
  var de = form.elements.de, ate = form.elements.ate;
  var espera = null, pedido = 0;

  function marcados(campo) {
    return Array.prototype.map.call(form.querySelectorAll('input[name="' + campo + '[]"]:checked'), function (c) { return c.value; });
  }

  // Linhas só das plantas escolhidas; máquinas só das plantas/linhas escolhidas. O que some é desmarcado.
  function cascata() {
    var plantas = marcados('plantas'), linhas;
    form.querySelectorAll('[data-multi="linhas"] .multi-opcao').forEach(function (op) {
      var ok = !plantas.length || plantas.indexOf(op.dataset.planta) >= 0;
      op.hidden = !ok; if (!ok) op.querySelector('input').checked = false;
    });
    linhas = marcados('linhas');
    form.querySelectorAll('[data-multi="maquinas"] .multi-opcao').forEach(function (op) {
      var ok = (!plantas.length || plantas.indexOf(op.dataset.planta) >= 0) && (!linhas.length || linhas.indexOf(op.dataset.linha) >= 0);
      op.hidden = !ok; if (!ok) op.querySelector('input').checked = false;
    });
    form.querySelectorAll('[data-multi]').forEach(function (bloco) {
      var n = bloco.querySelectorAll('.multi-opcao input:checked').length;
      bloco.querySelector('[data-resumo]').textContent = n === 0 ? 'Todas' : (n === 1 ? bloco.querySelector('.multi-opcao input:checked').closest('label').textContent.trim() : n + ' selecionadas');
    });
  }

  function parametros() {
    var p = new URLSearchParams();
    new FormData(form).forEach(function (v, k) { if (v !== '') p.append(k, v); });
    return p;
  }

  function atualizar() {
    if (!de.value || !ate.value) return;
    var p = parametros(), meu = ++pedido;
    history.replaceState(null, '', form.action + '?' + p.toString());
    p.append('parcial', '1');
    carregando.hidden = false;
    fetch(form.action + '?' + p.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
      .then(function (html) { if (meu === pedido) { resultado.innerHTML = html; falha.hidden = true; } })
      .catch(function () { if (meu === pedido) falha.hidden = false; })
      .finally(function () { if (meu === pedido) carregando.hidden = true; });
  }

  function agendar() { clearTimeout(espera); espera = setTimeout(atualizar, 300); }

  function mudou() { cascata(); if (salvos) salvos.value = ''; agendar(); }

  function formatar(d) {
    function z(n) { return (n < 10 ? '0' : '') + n; }
    return d.getFullYear() + '-' + z(d.getMonth() + 1) + '-' + z(d.getDate()) + 'T' + z(d.getHours()) + ':' + z(d.getMinutes());
  }
  function somarDias(valor, dias) { var d = new Date(valor); d.setDate(d.getDate() + dias); return formatar(d); }

  form.addEventListener('change', mudou);
  form.addEventListener('submit', function (e) { e.preventDefault(); atualizar(); });

  form.querySelectorAll('[data-dias]').forEach(function (b) {
    b.addEventListener('click', function () {
      var n = parseInt(b.dataset.dias, 10); de.value = somarDias(de.value, n); ate.value = somarDias(ate.value, n); mudou();
    });
  });
  form.querySelector('[data-hoje]').addEventListener('click', function () { de.value = form.dataset.hojeDe; ate.value = form.dataset.hojeAte; mudou(); });
  form.querySelectorAll('[data-turno-inicio]').forEach(function (b) {
    b.addEventListener('click', function () {
      // Turno no dia do "De". Turno que vira a noite termina no dia seguinte; o "Até" é o último minuto do turno.
      var dia = de.value.substring(0, 10), ini = dia + 'T' + b.dataset.turnoInicio, fim = dia + 'T' + b.dataset.turnoFim;
      if (fim <= ini) fim = somarDias(fim, 1);
      var f = new Date(fim); f.setMinutes(f.getMinutes() - 1);
      de.value = ini; ate.value = formatar(f); mudou();
    });
  });
  form.querySelectorAll('[data-todas]').forEach(function (b) {
    b.addEventListener('click', function () {
      b.closest('[data-multi]').querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = false; }); mudou();
    });
  });

  if (salvos) {
    salvos.addEventListener('change', function () {
      window.location.href = salvos.value ? form.action + '?filtro=' + encodeURIComponent(salvos.value) : form.action + '?' + parametros().toString();
    });
  }

  // "Salvar filtro" leva junto os valores atuais dos filtros.
  document.getElementById('tl-salvar').addEventListener('submit', function () {
    var destino = this;
    destino.querySelectorAll('[data-copia]').forEach(function (c) { c.remove(); });
    parametros().forEach(function (v, k) {
      var i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = v; i.setAttribute('data-copia', ''); destino.appendChild(i);
    });
  });

  // Período que inclui agora: atualiza sozinho a cada minuto (só com a aba visível).
  setInterval(function () {
    var vivo = resultado.querySelector('[data-ao-vivo="1"]');
    if (vivo && document.visibilityState === 'visible') atualizar();
  }, 60000);

  // Painéis de seleção: fecha ao clicar fora, ao apertar Esc e ao abrir outro.
  var paineis = form.querySelectorAll('details.multi');
  document.addEventListener('click', function (e) {
    paineis.forEach(function (d) { if (d.open && !d.contains(e.target)) d.open = false; });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') paineis.forEach(function (d) { d.open = false; });
  });
  paineis.forEach(function (d) {
    d.addEventListener('toggle', function () { if (d.open) paineis.forEach(function (o) { if (o !== d) o.open = false; }); });
  });

  cascata();
})();
</script>
@endsection
