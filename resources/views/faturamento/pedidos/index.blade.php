@extends('layouts.portal')

@section('titulo', 'Pedidos de venda')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Faturamento</p>
  <div class="tl-topo">
    <h1>Pedidos de venda</h1>
    <div class="tl-topo-acoes">
      <button type="button" id="pv-alternar-filtros" class="botao secundario pequeno" aria-expanded="false" aria-controls="pv-painel-filtros"
              title="Esconder ou mostrar os filtros (se recolhem sozinhos após 3 minutos sem mexer na tela)">
        @include('layouts.partials.icone', ['nome' => 'filtro']) <span data-rotulo>Mostrar filtros</span>
      </button>
    </div>
  </div>
  <p class="suave">Pedidos de venda do Protheus (SC5, itens SC6 e liberações SC9) pela etapa: o pedido fica na etapa do item mais atrasado.
    <small>@if ($ultimaSincronizacao)Sincronizado com o Protheus em @dataHora($ultimaSincronizacao). @endif @include('partials.proxima-sincronizacao', ['chave' => 'pedidos_venda'])</small></p>

  <form id="pv-filtros" class="tl-filtros" method="get" action="{{ route('faturamento.pedidos.index') }}">
    <input type="hidden" name="f" value="1">
    <div class="pv-mes" role="group" aria-label="Mês">
      <button type="button" class="botao secundario pequeno" data-mes-passo="-1" aria-label="Mês anterior">‹</button>
      <label>Mês <input type="month" name="mes" value="{{ $filtro->mes->format('Y-m') }}" required></label>
      <button type="button" class="botao secundario pequeno" data-mes-passo="1" aria-label="Próximo mês">›</button>
      <button type="button" class="botao secundario pequeno" data-mes-atual="{{ now(\App\Services\Faturamento\FiltroPedidosVenda::fuso())->format('Y-m') }}">Mês atual</button>
    </div>

    {{-- Com os filtros escondidos, uma linha mostra o que está aplicado (clique para mostrar os filtros). --}}
    <button type="button" id="pv-resumo-filtros" class="tl-resumo-filtros" title="Mostrar filtros">
      @include('layouts.partials.icone', ['nome' => 'filtro']) <span data-texto></span>
    </button>

    <div id="pv-painel-filtros" class="tl-painel-filtros" hidden>
      <div class="tl-selecoes">
        @include('producao.falta-produtos._multi', ['campo' => 'etapas', 'rotulo' => 'Etapa', 'opcoes' => $etapas, 'marcados' => $filtro->etapas])
        <label class="tl-salvos">Em aberto
          <select name="abertos">
            @foreach (\App\Services\Faturamento\FiltroPedidosVenda::ABERTOS as $valor => $rotulo)
              <option value="{{ $valor }}" @selected($filtro->abertos === $valor)>{{ $rotulo }}</option>
            @endforeach
          </select>
        </label>
        <label class="tl-salvos">Buscar
          <input type="search" name="busca" value="{{ $filtro->busca }}" maxlength="60" placeholder="Pedido, cliente, produto ou pedido do cliente"></label>
        <label class="tl-salvos">Filtros salvos
          <select id="pv-salvos" aria-label="Filtros salvos">
            <option value="">{{ $escolhido ? 'Personalizado' : '—' }}</option>
            @foreach ($salvos as $s)
              <option value="{{ $s->id }}" @selected($escolhido?->id === $s->id)>{{ $s->nome }}{{ $s->padrao ? ' ★ padrão' : '' }}</option>
            @endforeach
          </select>
        </label>
      </div>
      <p class="suave"><small>Faturados = faturamento no mês. Em aberto = pela entrega prevista (a mais próxima dos itens com saldo); "atrasados" inclui
        os de entrega anterior e os sem data. Nenhuma etapa marcada = todas. O mês não vai no filtro salvo: a tela sempre abre no mês atual.</small></p>
      <noscript><button class="botao" type="submit">Aplicar filtros</button></noscript>
    </div>
  </form>

  <div class="tl-acoes-filtro" id="pv-acoes-filtro" hidden>
    <details class="tl-salvar">
      <summary class="botao secundario pequeno">Salvar filtro</summary>
      <form id="pv-salvar" method="post" action="{{ route('faturamento.pedidos.filtros.salvar') }}" class="tl-salvar-form">
        @csrf
        <label>Nome <input name="nome" required maxlength="80" value="{{ $escolhido?->nome }}" placeholder="Ex.: Em separação"></label>
        <label class="opcao"><input type="checkbox" name="padrao" value="1" @checked($escolhido?->padrao)> <span>Abrir a tela com este filtro</span></label>
        <button class="botao pequeno" type="submit">Salvar</button>
        <small class="suave">Com o mesmo nome de um filtro já salvo, ele é atualizado.</small>
      </form>
    </details>
    @if ($escolhido)
      <form method="post" action="{{ route('faturamento.pedidos.filtros.excluir', $escolhido) }}" data-pergunta="Excluir o filtro “{{ $escolhido->nome }}”?"
            onsubmit="return confirm(this.dataset.pergunta)">
        @csrf @method('delete')
        <button class="botao secundario pequeno" type="submit">Excluir filtro "{{ $escolhido->nome }}"</button>
      </form>
    @endif
  </div>

  <p class="tl-avisos">
    <span id="pv-carregando" class="suave" hidden>Atualizando…</span>
    <span id="pv-falha" class="erro" hidden>Não foi possível atualizar. Tente de novo.</span>
  </p>
</section>

<div id="pv-resultado" aria-live="polite">
  @include('faturamento.pedidos._resultado')
</div>

<script>
(function () {
  var form = document.getElementById('pv-filtros');
  var resultado = document.getElementById('pv-resultado');
  var carregando = document.getElementById('pv-carregando');
  var falha = document.getElementById('pv-falha');
  var salvos = document.getElementById('pv-salvos');
  var espera = null, pedido = 0;

  function resumirMultis() {
    form.querySelectorAll('[data-multi]').forEach(function (bloco) {
      var marcados = bloco.querySelectorAll('.multi-opcao input:checked');
      bloco.querySelector('[data-resumo]').textContent = marcados.length === 0 ? 'Todas'
        : (marcados.length === 1 ? marcados[0].closest('label').textContent.trim() : marcados.length + ' selecionadas');
    });
  }

  function parametros() {
    var p = new URLSearchParams();
    new FormData(form).forEach(function (v, k) { if (v !== '') p.append(k, v); });
    return p;
  }

  // Troca só a área de resultado (cartões e lista), sem recarregar a página.
  function atualizar(url) {
    var meu = ++pedido;
    url = url || (form.action + '?' + parametros().toString());
    history.replaceState(null, '', url);
    carregando.hidden = false;
    fetch(url + (url.indexOf('?') >= 0 ? '&' : '?') + 'parcial=1', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
      .then(function (html) { if (meu === pedido) { resultado.innerHTML = html; falha.hidden = true; } })
      .catch(function () { if (meu === pedido) falha.hidden = false; })
      .finally(function () { if (meu === pedido) carregando.hidden = true; });
  }

  function agendar() { clearTimeout(espera); espera = setTimeout(function () { atualizar(); }, 350); }
  function mudou(e) {
    resumirMultis();
    if (salvos && !(e && e.target && e.target.name === 'mes')) salvos.value = '';
    textoResumo();
    agendar();
  }

  form.addEventListener('change', mudou);
  form.elements.busca.addEventListener('input', mudou);
  form.addEventListener('submit', function (e) { e.preventDefault(); atualizar(); });

  // Mês anterior / próximo / atual.
  form.querySelectorAll('[data-mes-passo]').forEach(function (b) {
    b.addEventListener('click', function () {
      var v = (form.elements.mes.value || '').split('-');
      if (v.length !== 2) return;
      var d = new Date(parseInt(v[0], 10), parseInt(v[1], 10) - 1 + parseInt(b.dataset.mesPasso, 10), 1);
      form.elements.mes.value = d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2);
      mudou({ target: form.elements.mes });
    });
  });
  form.querySelector('[data-mes-atual]').addEventListener('click', function () {
    form.elements.mes.value = this.dataset.mesAtual;
    mudou({ target: form.elements.mes });
  });
  form.querySelectorAll('[data-todas]').forEach(function (b) {
    b.addEventListener('click', function () {
      b.closest('[data-multi]').querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = false; });
      mudou();
    });
  });
  if (salvos) salvos.addEventListener('change', function () {
    window.location = form.action + '?mes=' + encodeURIComponent(form.elements.mes.value) + (salvos.value ? '&filtro=' + salvos.value : '&f=1');
  });
  // O "Salvar filtro" leva os filtros atuais.
  document.getElementById('pv-salvar').addEventListener('submit', function () {
    var destino = this;
    destino.querySelectorAll('input[data-copia]').forEach(function (i) { i.remove(); });
    parametros().forEach(function (v, k) {
      var i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = v; i.dataset.copia = '1'; destino.appendChild(i);
    });
  });
  // Links dentro do resultado (paginação e cartões que filtram a etapa) também trocam só o resultado.
  resultado.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-parcial]');
    if (!a || e.ctrlKey || e.metaKey) return;
    e.preventDefault();
    var url = new URL(a.href, window.location.href);
    url.searchParams.delete('parcial');
    // Cartão de etapa: marca a mesma escolha no formulário.
    if (a.dataset.etapa !== undefined) {
      form.querySelectorAll('input[name="etapas[]"]').forEach(function (c) { c.checked = c.value === a.dataset.etapa; });
      resumirMultis();
      textoResumo();
      if (salvos) salvos.value = '';
    }
    atualizar(url.pathname + url.search);
  });

  // Esconder/mostrar filtros: sem mexer na tela por 3 minutos, os filtros se recolhem sozinhos.
  var painel = document.getElementById('pv-painel-filtros');
  var acoes = document.getElementById('pv-acoes-filtro');
  var alternar = document.getElementById('pv-alternar-filtros');
  var resumoFiltros = document.getElementById('pv-resumo-filtros');
  var paineis = form.querySelectorAll('details.multi');
  var RECOLHER_APOS = 3 * 60 * 1000, ociosidade = null;

  function textoResumo() {
    var partes = [];
    form.querySelectorAll('[data-multi]').forEach(function (bloco) {
      partes.push(bloco.querySelector('.multi-rotulo').textContent + ': ' + bloco.querySelector('[data-resumo]').textContent);
    });
    partes.push('Em aberto: ' + form.elements.abertos.options[form.elements.abertos.selectedIndex].text);
    if (form.elements.busca.value) partes.push('Busca: ' + form.elements.busca.value);
    if (salvos && salvos.value) partes.push('Filtro: ' + salvos.options[salvos.selectedIndex].text);
    resumoFiltros.querySelector('[data-texto]').textContent = partes.join(' · ');
  }

  function mostrarFiltros(mostrar, lembrar) {
    painel.hidden = !mostrar;
    acoes.hidden = !mostrar;
    resumoFiltros.hidden = mostrar;
    alternar.setAttribute('aria-expanded', String(mostrar));
    alternar.querySelector('[data-rotulo]').textContent = mostrar ? 'Esconder filtros' : 'Mostrar filtros';
    if (!mostrar) {
      paineis.forEach(function (d) { d.open = false; });
      textoResumo();
    }
    if (lembrar) { try { localStorage.setItem('pv-filtros-visiveis', mostrar ? '1' : '0'); } catch (e) {} }
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
  document.addEventListener('click', function (e) {
    paineis.forEach(function (d) { if (d.open && !d.contains(e.target)) d.open = false; });
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') paineis.forEach(function (d) { d.open = false; }); });

  resumirMultis();
  var lembrado = null;
  try { lembrado = localStorage.getItem('pv-filtros-visiveis'); } catch (e) {}
  mostrarFiltros(lembrado === '1', false);
})();
</script>
@endsection
