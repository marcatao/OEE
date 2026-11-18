@extends('layouts.portal')

@section('titulo', 'Timeline')

@section('conteudo')
@php($u = auth()->user())
<section class="cartao">
  <p class="trilha">Dashboards</p>
  <div class="tl-topo">
    <h1>Timeline</h1>
    <div class="tl-topo-acoes">
      <button type="button" id="tl-alternar-filtros" class="botao secundario pequeno" aria-expanded="false" aria-controls="tl-painel-filtros"
              title="Esconder ou mostrar os filtros (se recolhem sozinhos após 3 minutos sem mexer na tela)">
        @include('layouts.partials.icone', ['nome' => 'filtro']) <span data-rotulo>Mostrar filtros</span>
      </button>
      @if ($u->pode('apontamentos'))
        <a class="botao" href="{{ route('apontamentos.create', ['voltar' => 'timeline']) }}">@include('layouts.partials.icone', ['nome' => 'mais']) Novo apontamento</a>
      @endif
    </div>
  </div>

  {{-- Os filtros sempre começam escondidos. Com eles escondidos, uma linha mostra o que está aplicado (clique para mostrar os filtros). --}}
  <button type="button" id="tl-resumo-filtros" class="tl-resumo-filtros" title="Mostrar filtros">
    @include('layouts.partials.icone', ['nome' => 'filtro']) <span data-texto></span>
  </button>

  <div id="tl-painel-filtros" class="tl-painel-filtros" hidden>
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
  </div>
  </div>{{-- fim do painel de filtros --}}

  <p class="tl-avisos">
    <span id="tl-carregando" class="suave" hidden>Atualizando…</span>
    <span id="tl-falha" class="erro" hidden>Não foi possível atualizar. Tentando de novo em instantes.</span>
  </p>

  <div id="tl-resultado" aria-live="polite">
    @include('dashboard.timeline._resultado')
  </div>

  @if ($u->pode('apontamentos'))
    {{-- Informar a OP de um apontamento cuja legenda exige OP (aberto pelo bloco piscando). Só OPs abertas. --}}
    <dialog id="tl-dialogo-op" class="dialogo">
      <form method="dialog" id="tl-form-op">
        <h2>Informar ordem de produção</h2>
        <p class="suave" data-op-descricao></p>
        @include('partials.campo-op', ['op' => null, 'ajuda' => 'Só ordens abertas (não fechadas no Protheus). Digite o número da OP ou o produto.'])
        <p class="erro" data-op-erro hidden></p>
        <div class="acoes">
          <button class="botao" type="submit" value="gravar">Gravar OP</button>
          <button class="botao secundario" type="button" data-op-cancelar>Cancelar</button>
        </div>
        <small class="suave">A OP é gravada no apontamento e passa a ser a OP atual da máquina.</small>
      </form>
    </dialog>
  @endif

  @if ($u->pode('apontamentos'))
    {{-- Parada sem motivo (98): escolher a legenda de parada daquele apontamento. --}}
    <dialog id="tl-dialogo-motivo" class="dialogo">
      <form method="dialog" id="tl-form-motivo">
        <h2>Informar motivo da parada</h2>
        <p class="suave" data-motivo-descricao></p>
        <label>Motivo (legenda de parada)
          <select name="legenda_id" data-busca="Buscar legenda…">
            <option value="">Escolha…</option>
            @foreach ($legendasMotivo as $l)<option value="{{ $l->id }}">{{ $l->codigo }} — {{ $l->descricao }}</option>@endforeach
          </select></label>
        <p class="erro" data-motivo-erro hidden></p>
        <div class="acoes">
          <button class="botao" type="submit" value="gravar">Gravar motivo</button>
          <button class="botao secundario" type="button" data-motivo-cancelar>Cancelar</button>
        </div>
        <small class="suave">A legenda é trocada só neste apontamento.</small>
      </form>
    </dialog>

    {{-- "Trocar OP" da máquina: 1) escolhe a OP (só abertas); 2) escolhe se troca no apontamento atual ou num novo. --}}
    <dialog id="tl-dialogo-troca" class="dialogo">
      <form method="dialog" id="tl-form-troca">
        <h2>Trocar ordem de produção</h2>
        <p class="suave" data-troca-maquina></p>
        <div data-passo="1">
          @include('partials.campo-op', ['op' => null, 'nome' => 'ordem_producao_troca', 'rotulo' => 'Nova ordem de produção',
            'ajuda' => 'Só ordens abertas (não fechadas no Protheus). Digite o número da OP ou o produto.'])
          <div class="acoes">
            <button class="botao" type="button" data-troca-continuar>Confirmar OP</button>
            <button class="botao secundario" type="button" data-troca-cancelar>Cancelar</button>
          </div>
        </div>
        <div data-passo="2" hidden>
          <p>OP <strong data-troca-nova></strong>. Como lançar?</p>
          <div class="tl-troca-modos">
            <button class="botao secundario" type="button" data-troca-modo="atual">Trocar no apontamento atual
              <small>Todo o apontamento em andamento passa para a OP nova.</small></button>
            <button class="botao" type="button" data-troca-modo="novo">Novo apontamento a partir de agora
              <small>Encerra o apontamento atual (com a OP anterior até agora) e começa outro na mesma legenda com a OP nova.</small></button>
          </div>
          <button class="botao secundario pequeno" type="button" data-troca-voltar>Voltar</button>
        </div>
        <p class="erro" data-troca-erro hidden></p>
      </form>
    </dialog>
  @endif

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

  // Bloco piscando (legenda exige OP e o apontamento está sem): abre a escolha da OP; ao gravar, atualiza a timeline.
  var dialogo = document.getElementById('tl-dialogo-op');
  if (dialogo) {
    var formOp = document.getElementById('tl-form-op'), selOp = formOp.querySelector('select'), erroOp = formOp.querySelector('[data-op-erro]');
    var urlOp = null;
    resultado.addEventListener('click', function (e) {
      var bloco = e.target.closest('[data-informar-op]');
      if (!bloco) return;
      urlOp = bloco.dataset.informarOp;
      formOp.querySelector('[data-op-descricao]').textContent = bloco.dataset.maquina + ' · ' + bloco.dataset.descricao;
      selOp.value = ''; selOp.dispatchEvent(new Event('change'));
      erroOp.hidden = true;
      dialogo.showModal();
    });
    formOp.querySelector('[data-op-cancelar]').addEventListener('click', function () { dialogo.close(); });
    formOp.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!selOp.value) { erroOp.textContent = 'Escolha a ordem de produção.'; erroOp.hidden = false; return; }
      fetch(urlOp, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
          'X-CSRF-TOKEN': '{{ csrf_token() }}' }, body: JSON.stringify({ ordem_producao: selOp.value }) })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (!res.ok) { erroOp.textContent = (res.j.errors && res.j.errors.ordem_producao) ? res.j.errors.ordem_producao[0] : (res.j.message || 'Não foi possível gravar.'); erroOp.hidden = false; return; }
          dialogo.close(); atualizar();
        })
        .catch(function () { erroOp.textContent = 'Não foi possível gravar. Tente de novo.'; erroOp.hidden = false; });
    });
  }

  // Parada sem motivo (aviso no topo ou bloco piscando): escolhe a legenda; ao gravar, atualiza (aparecem as próximas).
  var dMotivo = document.getElementById('tl-dialogo-motivo');
  if (dMotivo) {
    var fMotivo = document.getElementById('tl-form-motivo'), selMotivo = fMotivo.querySelector('select'), erroMotivo = fMotivo.querySelector('[data-motivo-erro]');
    var urlMotivo = null;
    resultado.addEventListener('click', function (e) {
      var alvo = e.target.closest('[data-informar-motivo]');
      if (!alvo) return;
      urlMotivo = alvo.dataset.informarMotivo;
      fMotivo.querySelector('[data-motivo-descricao]').textContent = alvo.dataset.maquina + ' · ' + alvo.dataset.descricao;
      selMotivo.value = ''; selMotivo.dispatchEvent(new Event('change'));
      erroMotivo.hidden = true;
      dMotivo.showModal();
    });
    fMotivo.querySelector('[data-motivo-cancelar]').addEventListener('click', function () { dMotivo.close(); });
    fMotivo.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!selMotivo.value) { erroMotivo.textContent = 'Escolha o motivo da parada.'; erroMotivo.hidden = false; return; }
      fetch(urlMotivo, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
          'X-CSRF-TOKEN': '{{ csrf_token() }}' }, body: JSON.stringify({ legenda_id: selMotivo.value }) })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (!res.ok) { erroMotivo.textContent = (res.j.errors && res.j.errors.legenda_id) ? res.j.errors.legenda_id[0] : (res.j.message || 'Não foi possível gravar.'); erroMotivo.hidden = false; return; }
          dMotivo.close(); atualizar();
        })
        .catch(function () { erroMotivo.textContent = 'Não foi possível gravar. Tente de novo.'; erroMotivo.hidden = false; });
    });
  }

  // "Trocar OP" (ao lado do número da OP): escolhe a OP, confirma e escolhe o modo; ao gravar, atualiza a timeline.
  var dTroca = document.getElementById('tl-dialogo-troca');
  if (dTroca) {
    var fTroca = document.getElementById('tl-form-troca'), selTroca = fTroca.querySelector('select'), erroTroca = fTroca.querySelector('[data-troca-erro]');
    var urlTroca = null;
    function passo(n) { fTroca.querySelectorAll('[data-passo]').forEach(function (p) { p.hidden = p.dataset.passo !== String(n); }); }
    function erro(t) { erroTroca.textContent = t; erroTroca.hidden = !t; }
    resultado.addEventListener('click', function (e) {
      var link = e.target.closest('[data-trocar-op]');
      if (!link) return;
      urlTroca = link.dataset.trocarOp;
      fTroca.querySelector('[data-troca-maquina]').textContent = link.dataset.maquina + (link.dataset.op ? ' · OP atual ' + link.dataset.op : ' · sem OP');
      selTroca.value = ''; selTroca.dispatchEvent(new Event('change'));
      erro(''); passo(1);
      dTroca.showModal();
    });
    fTroca.querySelector('[data-troca-cancelar]').addEventListener('click', function () { dTroca.close(); });
    fTroca.querySelector('[data-troca-voltar]').addEventListener('click', function () { erro(''); passo(1); });
    fTroca.querySelector('[data-troca-continuar]').addEventListener('click', function () {
      if (!selTroca.value) { erro('Escolha a ordem de produção.'); return; }
      erro('');
      fTroca.querySelector('[data-troca-nova]').textContent = selTroca.value;
      passo(2);
    });
    fTroca.querySelectorAll('[data-troca-modo]').forEach(function (botao) {
      botao.addEventListener('click', function () {
        fetch(urlTroca, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}' }, body: JSON.stringify({ ordem_producao: selTroca.value, modo: botao.dataset.trocaModo }) })
          .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
            if (!res.ok) { erro((res.j.errors && (res.j.errors.ordem_producao || res.j.errors.modo)) ? (res.j.errors.ordem_producao || res.j.errors.modo)[0] : (res.j.message || 'Não foi possível trocar.')); return; }
            dTroca.close(); atualizar();
          })
          .catch(function () { erro('Não foi possível trocar. Tente de novo.'); });
      });
    });
  }

  // Período que inclui agora: atualiza sozinho a cada minuto (só com a aba visível).
  setInterval(function () {
    var vivo = resultado.querySelector('[data-ao-vivo="1"]');
    if (vivo && document.visibilityState === 'visible') atualizar();
  }, 60000);

  // Esconder/mostrar filtros. A escolha manual fica lembrada neste navegador; sem mexer na tela por 3 minutos, os
  // filtros se recolhem sozinhos (para o gráfico ganhar espaço, ex.: na TV da fábrica).
  var painel = document.getElementById('tl-painel-filtros');
  var alternar = document.getElementById('tl-alternar-filtros');
  var resumoFiltros = document.getElementById('tl-resumo-filtros');
  var RECOLHER_APOS = 3 * 60 * 1000, ociosidade = null;

  function textoResumo() {
    function dataHora(v) { return v ? v.substring(8, 10) + '/' + v.substring(5, 7) + ' ' + v.substring(11, 16) : ''; }
    var partes = [dataHora(de.value) + ' até ' + dataHora(ate.value)];
    form.querySelectorAll('[data-multi]').forEach(function (bloco) {
      partes.push(bloco.querySelector('.multi-rotulo').textContent + ': ' + bloco.querySelector('[data-resumo]').textContent);
    });
    if (salvos && salvos.value) partes.push('Filtro: ' + salvos.options[salvos.selectedIndex].text);
    return partes.join(' · ');
  }

  function mostrarFiltros(mostrar, lembrar) {
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

  function contarOciosidade() {
    clearTimeout(ociosidade);
    if (!painel.hidden) ociosidade = setTimeout(function () { mostrarFiltros(false, false); }, RECOLHER_APOS);
  }

  alternar.addEventListener('click', function () { mostrarFiltros(painel.hidden, true); });
  resumoFiltros.addEventListener('click', function () { mostrarFiltros(true, true); });
  ['mousemove', 'mousedown', 'keydown', 'wheel', 'touchstart', 'focusin'].forEach(function (evento) {
    document.addEventListener(evento, function () { if (!painel.hidden) contarOciosidade(); }, { passive: true });
  });

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
  // Sempre começa com os filtros escondidos (só a linha de resumo); o botão "Mostrar filtros" abre.
  mostrarFiltros(false, false);
})();
</script>
@endsection
