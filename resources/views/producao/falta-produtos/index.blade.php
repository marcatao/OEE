@extends('layouts.portal')

@section('titulo', 'Falta de produtos')

@section('conteudo')
@php($cores = collect($statusOpcoes)->map(fn ($s) => 'falta-'.$s['cor'])->all())
<section class="cartao">
  <p class="trilha">Produção</p>
  <div class="tl-topo">
    <h1>Falta de produtos</h1>
    <div class="tl-topo-acoes">
      <button type="button" id="fp-alternar-filtros" class="botao secundario pequeno" aria-expanded="false" aria-controls="fp-painel-filtros"
              title="Esconder ou mostrar os filtros (se recolhem sozinhos após 3 minutos sem mexer na tela)">
        @include('layouts.partials.icone', ['nome' => 'filtro']) <span data-rotulo>Mostrar filtros</span>
      </button>
    </div>
  </div>
  <p class="suave">Produtos que estão faltando ser produzidos (ZB5 do Protheus), com a situação da produção de cada um: OP aberta,
    máquina com a OP e apontamento real produzindo.
    <small>@if ($ultimaSincronizacao)Sincronizado com o Protheus em @dataHora($ultimaSincronizacao). @endif @include('partials.proxima-sincronizacao', ['chave' => 'faltas_produto'])</small></p>

  <div class="falta-legendas" aria-label="Legendas">
    @foreach ($statusOpcoes as $s)
      <span><i class="falta-bola falta-{{ $s['cor'] }}" aria-hidden="true"></i>{{ $s['rotulo'] }}</span>
    @endforeach
  </div>

  {{-- Com os filtros escondidos, uma linha mostra o que está aplicado (clique para mostrar os filtros). --}}
  <button type="button" id="fp-resumo-filtros" class="tl-resumo-filtros" title="Mostrar filtros">
    @include('layouts.partials.icone', ['nome' => 'filtro']) <span data-texto></span>
  </button>

  <div id="fp-painel-filtros" class="tl-painel-filtros" hidden>
  <form id="fp-filtros" class="tl-filtros" method="get" action="{{ route('falta-produtos.index') }}">
    <input type="hidden" name="f" value="1">
    <div class="tl-periodo">
      <label>Solicitada de <input type="date" name="de" value="{{ $filtro->de?->format('Y-m-d') }}"></label>
      <label>até <input type="date" name="ate" value="{{ $filtro->ate?->format('Y-m-d') }}"></label>
      <div class="tl-atalhos" role="group" aria-label="Atalhos de período">
        <button type="button" class="botao secundario pequeno" data-periodo="mes">Este mês</button>
        <button type="button" class="botao secundario pequeno" data-periodo="30">Últimos 30 dias</button>
        <button type="button" class="botao secundario pequeno" data-periodo="ano">Este ano</button>
        <button type="button" class="botao secundario pequeno" data-periodo="">Sem limite</button>
      </div>
    </div>
    <div class="tl-selecoes">
      @include('producao.falta-produtos._multi', ['campo' => 'status', 'rotulo' => 'Legenda',
        'opcoes' => collect($statusOpcoes)->map(fn ($s) => $s['rotulo'])->all(), 'marcados' => $filtro->status, 'cores' => $cores])
      @include('producao.falta-produtos._multi', ['campo' => 'producao', 'rotulo' => 'Produção',
        'opcoes' => $producaoOpcoes, 'marcados' => $filtro->producao])
      <label class="tl-salvos">Buscar
        <input type="search" name="busca" value="{{ $filtro->busca }}" maxlength="60" placeholder="Produto, descrição, documento, pedido ou solicitante"></label>
      <label class="tl-salvos">Filtros salvos
        <select id="fp-salvos" aria-label="Filtros salvos">
          <option value="">{{ $escolhido ? 'Personalizado' : '—' }}</option>
          @foreach ($salvos as $s)
            <option value="{{ $s->id }}" @selected($escolhido?->id === $s->id)>{{ $s->nome }}{{ $s->padrao ? ' ★ padrão' : '' }}</option>
          @endforeach
        </select>
      </label>
    </div>
    <p class="suave"><small>Padrão: tudo que não está encerrado (pendente e atendida parcialmente), sem limite de data. Nenhuma legenda marcada = todas.</small></p>
    <noscript><button class="botao" type="submit">Aplicar filtros</button></noscript>
  </form>

  <div class="tl-acoes-filtro">
    <details class="tl-salvar">
      <summary class="botao secundario pequeno">Salvar filtro</summary>
      <form id="fp-salvar" method="post" action="{{ route('falta-produtos.filtros.salvar') }}" class="tl-salvar-form">
        @csrf
        <label>Nome <input name="nome" required maxlength="80" value="{{ $escolhido?->nome }}" placeholder="Ex.: Pendentes sem OP"></label>
        <label class="opcao"><input type="checkbox" name="padrao" value="1" @checked($escolhido?->padrao)> <span>Abrir a tela com este filtro</span></label>
        <button class="botao pequeno" type="submit">Salvar</button>
        <small class="suave">Com o mesmo nome de um filtro já salvo, ele é atualizado.</small>
      </form>
    </details>
    @if ($escolhido)
      <form method="post" action="{{ route('falta-produtos.filtros.excluir', $escolhido) }}" data-pergunta="Excluir o filtro “{{ $escolhido->nome }}”?"
            onsubmit="return confirm(this.dataset.pergunta)">
        @csrf @method('delete')
        <button class="botao secundario pequeno" type="submit">Excluir filtro "{{ $escolhido->nome }}"</button>
      </form>
    @endif
  </div>
  </div>{{-- fim do painel de filtros --}}

  <p class="tl-avisos">
    <span id="fp-carregando" class="suave" hidden>Atualizando…</span>
    <span id="fp-falha" class="erro" hidden>Não foi possível atualizar. Tente de novo.</span>
  </p>
</section>

<div id="fp-resultado" aria-live="polite">
  @include('producao.falta-produtos._resultado')
</div>

<script>
(function () {
  var form = document.getElementById('fp-filtros');
  var resultado = document.getElementById('fp-resultado');
  var carregando = document.getElementById('fp-carregando');
  var falha = document.getElementById('fp-falha');
  var salvos = document.getElementById('fp-salvos');
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

  // Troca só a área de resultado (lista e painéis), sem recarregar a página.
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
  function mudou() { resumirMultis(); if (salvos) salvos.value = ''; agendar(); }

  form.addEventListener('change', mudou);
  form.elements.busca.addEventListener('input', mudou);
  form.addEventListener('submit', function (e) { e.preventDefault(); atualizar(); });

  form.querySelectorAll('[data-periodo]').forEach(function (b) {
    b.addEventListener('click', function () {
      function iso(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
      var hoje = new Date(), de = '', ate = '';
      if (b.dataset.periodo === 'mes') { de = iso(new Date(hoje.getFullYear(), hoje.getMonth(), 1)); ate = iso(hoje); }
      if (b.dataset.periodo === '30') { var d = new Date(hoje); d.setDate(d.getDate() - 29); de = iso(d); ate = iso(hoje); }
      if (b.dataset.periodo === 'ano') { de = hoje.getFullYear() + '-01-01'; ate = iso(hoje); }
      form.elements.de.value = de; form.elements.ate.value = ate; mudou();
    });
  });
  form.querySelectorAll('[data-todas]').forEach(function (b) {
    b.addEventListener('click', function () {
      b.closest('[data-multi]').querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = false; });
      mudou();
    });
  });
  if (salvos) salvos.addEventListener('change', function () {
    window.location = form.action + (salvos.value ? '?filtro=' + salvos.value : '');
  });
  // O "Salvar filtro" leva os filtros atuais.
  document.getElementById('fp-salvar').addEventListener('submit', function () {
    var destino = this;
    destino.querySelectorAll('input[data-copia]').forEach(function (i) { i.remove(); });
    parametros().forEach(function (v, k) {
      var i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = v; i.dataset.copia = '1'; destino.appendChild(i);
    });
  });
  // Links dentro do resultado (paginação e cartões que filtram) também trocam só o resultado.
  resultado.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-parcial]');
    if (!a || e.ctrlKey || e.metaKey) return;
    e.preventDefault();
    var url = new URL(a.href, window.location.href);
    url.searchParams.delete('parcial');
    // Cartão de produção: marca a mesma escolha no formulário.
    if (a.dataset.producao !== undefined) {
      form.querySelectorAll('input[name="producao[]"]').forEach(function (c) { c.checked = c.value === a.dataset.producao; });
      resumirMultis();
      if (salvos) salvos.value = '';
    }
    atualizar(url.pathname + url.search);
  });

  // Esconder/mostrar filtros: sem mexer na tela por 3 minutos, os filtros se recolhem sozinhos.
  var painel = document.getElementById('fp-painel-filtros');
  var alternar = document.getElementById('fp-alternar-filtros');
  var resumoFiltros = document.getElementById('fp-resumo-filtros');
  var paineis = form.querySelectorAll('details.multi');
  var RECOLHER_APOS = 3 * 60 * 1000, ociosidade = null;

  function textoResumo() {
    function data(v) { return v ? v.substring(8, 10) + '/' + v.substring(5, 7) + '/' + v.substring(0, 4) : ''; }
    var de = form.elements.de.value, ate = form.elements.ate.value;
    var partes = [de || ate ? 'Solicitadas ' + (de ? 'de ' + data(de) : '') + (ate ? ' até ' + data(ate) : '') : 'Sem limite de data'];
    form.querySelectorAll('[data-multi]').forEach(function (bloco) {
      partes.push(bloco.querySelector('.multi-rotulo').textContent + ': ' + bloco.querySelector('[data-resumo]').textContent);
    });
    if (form.elements.busca.value) partes.push('Busca: ' + form.elements.busca.value);
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
    if (lembrar) { try { localStorage.setItem('fp-filtros-visiveis', mostrar ? '1' : '0'); } catch (e) {} }
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
  paineis.forEach(function (d) {
    d.addEventListener('toggle', function () { if (d.open) paineis.forEach(function (o) { if (o !== d) o.open = false; }); });
  });

  resumirMultis();
  var lembrado = null;
  try { lembrado = localStorage.getItem('fp-filtros-visiveis'); } catch (e) {}
  mostrarFiltros(lembrado === '1', false);
})();
</script>
@endsection
