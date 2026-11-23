@extends('layouts.portal')

@section('titulo', 'Produção por hora')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Dashboards</p>
  <div class="tl-topo">
    <div>
      <h1>Produção por hora</h1>
      <p class="suave" style="margin:0">Peças contadas pelo IoT em cada hora do turno de cada máquina, com a OP e o produto. A meta em peças por minuto vem do item MO da estrutura da OP atual (Protheus).</p>
    </div>
    <div class="tl-topo-acoes">
      <button type="button" id="ph-alternar-filtros" class="botao secundario pequeno" aria-expanded="false" aria-controls="ph-painel-filtros"
              title="Esconder ou mostrar os filtros (se recolhem sozinhos após 3 minutos sem mexer na tela)">
        @include('layouts.partials.icone', ['nome' => 'filtro']) <span data-rotulo>Mostrar filtros</span>
      </button>
    </div>
  </div>

  <button type="button" id="ph-resumo-filtros" class="tl-resumo-filtros" title="Mostrar filtros">
    @include('layouts.partials.icone', ['nome' => 'filtro']) <span data-texto></span>
  </button>

  <div id="ph-painel-filtros" class="tl-painel-filtros" hidden>
    <form id="ph-filtros" class="tl-filtros" method="get" action="{{ route('producao-hora') }}" data-hoje="{{ $hoje->format('Y-m-d') }}">
      <div class="tl-periodo">
        <label>Dia <input type="date" name="dia" value="{{ $filtro->dia->format('Y-m-d') }}" max="{{ $hoje->format('Y-m-d') }}" required></label>
        <div class="tl-atalhos" role="group" aria-label="Atalhos de dia">
          <button type="button" class="topo-icone" data-dias="-1" title="Um dia antes" aria-label="Um dia antes">@include('layouts.partials.icone', ['nome' => 'anterior'])</button>
          <button type="button" class="botao secundario pequeno" data-hoje>Hoje</button>
          <button type="button" class="topo-icone" data-dias="1" title="Um dia depois" aria-label="Um dia depois">@include('layouts.partials.icone', ['nome' => 'seta'])</button>
        </div>
        <small class="suave">Cada máquina mostra o turno dela no dia (calendário e jornada do cadastro).</small>
      </div>

      <div class="tl-selecoes">
        @include('dashboard.timeline._multi', ['campo' => 'plantas', 'rotulo' => 'Plantas', 'itens' => $opcoes['plantas'],
          'marcados' => $filtro->plantas, 'texto' => fn ($p) => $p->nome, 'dados' => fn ($p) => ['planta' => $p->id]])
        @include('dashboard.timeline._multi', ['campo' => 'linhas', 'rotulo' => 'Linhas', 'itens' => $opcoes['linhas'],
          'marcados' => $filtro->linhas, 'texto' => fn ($l) => $l->nome.($l->planta ? ' · '.$l->planta->nome : ''), 'dados' => fn ($l) => ['planta' => $l->planta_id, 'linha' => $l->id]])
        @include('dashboard.timeline._multi', ['campo' => 'maquinas', 'rotulo' => 'Máquinas', 'itens' => $opcoes['maquinas'],
          'marcados' => $filtro->maquinas, 'texto' => fn ($m) => $m->nome.' ('.$m->codigo.')', 'dados' => fn ($m) => ['planta' => $m->linha?->planta_id, 'linha' => $m->linha_id]])

        <label class="tl-salvos">Filtros salvos
          <select id="ph-salvos" aria-label="Filtros salvos">
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
        <form id="ph-salvar" method="post" action="{{ route('producao-hora.filtros.salvar') }}" class="tl-salvar-form">
          @csrf
          <label>Nome <input name="nome" required maxlength="80" value="{{ $escolhido?->nome }}" placeholder="Ex.: Injetoras da linha 1"></label>
          <fieldset>
            <legend>Dia</legend>
            <label class="opcao"><input type="radio" name="periodo" value="hoje" @checked(($escolhido?->filtros['periodo'] ?? 'hoje') === 'hoje')>
              <span>Dia atual <small>sempre o dia em que a tela for aberta</small></span></label>
            <label class="opcao"><input type="radio" name="periodo" value="fixo" @checked(($escolhido?->filtros['periodo'] ?? '') === 'fixo')>
              <span>Dia fixo <small>sempre o dia escolhido agora</small></span></label>
          </fieldset>
          <label class="opcao"><input type="checkbox" name="padrao" value="1" @checked($escolhido?->padrao)> <span>Abrir a tela com este filtro</span></label>
          <button class="botao pequeno" type="submit">Salvar</button>
          <small class="suave">Com o mesmo nome de um filtro já salvo, ele é atualizado.</small>
        </form>
      </details>
      @if ($escolhido)
        <form method="post" action="{{ route('producao-hora.filtros.excluir', $escolhido) }}" data-pergunta="Excluir o filtro “{{ $escolhido->nome }}”?"
              onsubmit="return confirm(this.dataset.pergunta)">
          @csrf @method('delete')
          <button class="botao secundario pequeno" type="submit">Excluir filtro "{{ $escolhido->nome }}"</button>
        </form>
      @endif
    </div>
  </div>

  <p class="tl-avisos">
    <span id="ph-carregando" class="suave" hidden>Atualizando…</span>
    <span id="ph-falha" class="erro" hidden>Não foi possível atualizar. Tentando de novo em instantes.</span>
  </p>

  <div id="ph-resultado" aria-live="polite">
    @include('dashboard.producao-hora._resultado')
  </div>
</section>

<script>
(function () {
  var form = document.getElementById('ph-filtros');
  var resultado = document.getElementById('ph-resultado');
  var carregando = document.getElementById('ph-carregando');
  var falha = document.getElementById('ph-falha');
  var salvos = document.getElementById('ph-salvos');
  var dia = form.elements.dia;
  var espera = null, pedido = 0;

  function marcados(campo) {
    return Array.prototype.map.call(form.querySelectorAll('input[name="' + campo + '[]"]:checked'), function (c) { return c.value; });
  }

  // Linhas só das plantas escolhidas; máquinas só das plantas/linhas escolhidas (como na Timeline).
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
    if (!dia.value) return;
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

  function mudou() { cascata(); if (salvos) salvos.value = ''; clearTimeout(espera); espera = setTimeout(atualizar, 300); }

  function somarDias(valor, dias) {
    var d = new Date(valor + 'T12:00:00'); d.setDate(d.getDate() + dias);
    var v = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    return v > form.dataset.hoje ? form.dataset.hoje : v;
  }

  form.addEventListener('change', mudou);
  form.addEventListener('submit', function (e) { e.preventDefault(); atualizar(); });
  form.querySelectorAll('[data-dias]').forEach(function (b) {
    b.addEventListener('click', function () { dia.value = somarDias(dia.value, parseInt(b.dataset.dias, 10)); mudou(); });
  });
  form.querySelector('[data-hoje]').addEventListener('click', function () { dia.value = form.dataset.hoje; mudou(); });
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
  document.getElementById('ph-salvar').addEventListener('submit', function () {
    var destino = this;
    destino.querySelectorAll('[data-copia]').forEach(function (c) { c.remove(); });
    parametros().forEach(function (v, k) {
      var i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = v; i.setAttribute('data-copia', ''); destino.appendChild(i);
    });
  });

  // Dia de hoje (turno em andamento): atualiza sozinho a cada minuto, só com a aba visível.
  setInterval(function () {
    if (resultado.querySelector('[data-ao-vivo="1"]') && document.visibilityState === 'visible') atualizar();
  }, 60000);

  // Esconder/mostrar filtros; sem mexer na tela por 3 minutos, eles se recolhem sozinhos (ex.: na TV da fábrica).
  var painel = document.getElementById('ph-painel-filtros');
  var alternar = document.getElementById('ph-alternar-filtros');
  var resumoFiltros = document.getElementById('ph-resumo-filtros');
  var paineis = form.querySelectorAll('details.multi');
  var ociosidade = null;

  function textoResumo() {
    var v = dia.value, partes = [v === form.dataset.hoje ? 'Hoje' : v.substring(8, 10) + '/' + v.substring(5, 7) + '/' + v.substring(0, 4)];
    form.querySelectorAll('[data-multi]').forEach(function (bloco) {
      partes.push(bloco.querySelector('.multi-rotulo').textContent + ': ' + bloco.querySelector('[data-resumo]').textContent);
    });
    if (salvos && salvos.value) partes.push('Filtro: ' + salvos.options[salvos.selectedIndex].text);
    return partes.join(' · ');
  }
  function contarOciosidade() {
    clearTimeout(ociosidade);
    if (!painel.hidden) ociosidade = setTimeout(function () { mostrarFiltros(false); }, 3 * 60 * 1000);
  }
  function mostrarFiltros(mostrar) {
    painel.hidden = !mostrar;
    resumoFiltros.hidden = mostrar;
    alternar.setAttribute('aria-expanded', String(mostrar));
    alternar.querySelector('[data-rotulo]').textContent = mostrar ? 'Esconder filtros' : 'Mostrar filtros';
    if (!mostrar) {
      paineis.forEach(function (d) { d.open = false; });
      resumoFiltros.querySelector('[data-texto]').textContent = textoResumo();
    }
    contarOciosidade();
  }
  alternar.addEventListener('click', function () { mostrarFiltros(painel.hidden); });
  resumoFiltros.addEventListener('click', function () { mostrarFiltros(true); });
  ['mousemove', 'mousedown', 'keydown', 'wheel', 'touchstart', 'focusin'].forEach(function (evento) {
    document.addEventListener(evento, function () { if (!painel.hidden) contarOciosidade(); }, { passive: true });
  });
  document.addEventListener('click', function (e) {
    paineis.forEach(function (d) { if (d.open && !d.contains(e.target)) d.open = false; });
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') paineis.forEach(function (d) { d.open = false; }); });
  paineis.forEach(function (d) {
    d.addEventListener('toggle', function () { if (d.open) paineis.forEach(function (o) { if (o !== d) o.open = false; }); });
  });

  cascata();
  mostrarFiltros(false);
})();
</script>
@endsection
