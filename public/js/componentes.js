/*
 * Componentes das telas do portal (sem dependências nem etapa de build).
 *
 * 1) <select data-busca> — lista com busca: troca a caixa de seleção por um botão que abre um painel com um campo de
 *    busca (por código ou nome) e a lista filtrada. Com data-busca-url="..." (listas enormes, ex.: produtos) as opções
 *    vêm do servidor enquanto se digita: GET url?q=texto → [{valor, texto}] (mínimo 2 letras). O <select> original continua no formulário (escondido) e é ele que
 *    envia o valor. Para mudar por código: select.value = 'x'; select.dispatchEvent(new Event('change')).
 *    select.disabled = true trava o campo (lembre que select desabilitado não é enviado no formulário).
 *    data-compacto: caixa mais baixa e texto menor (ex.: campo de OP).
 *
 * 3) [data-abas] — abas: botões [data-aba="x"] em .abas-lista e painéis [data-painel="x"]. Abre a aba do endereço
 *    (#aba-x), senão a de data-aba-inicial; trocar de aba atualiza o endereço (o link e o voltar levam à mesma aba).
 *
 * 2) [data-escolha] — escolha de vários com busca (partial partials/escolha-multipla): <details> com o resumo do que
 *    está marcado; ao abrir, busca por nome e a lista de caixas de marcar.
 */
(function () {
  'use strict';

  function semAcento(texto) {
    return (texto || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  }

  // ---------- 1) Lista com busca ----------
  function montarBusca(select) {
    if (select.dataset.buscaPronta) return;
    select.dataset.buscaPronta = '1';

    var caixa = document.createElement('div');
    caixa.className = 'busca-select';
    var botao = document.createElement('button');
    botao.type = 'button';
    botao.className = 'busca-select-botao';
    botao.setAttribute('aria-haspopup', 'listbox');
    botao.setAttribute('aria-expanded', 'false');
    if (select.getAttribute('aria-label')) botao.setAttribute('aria-label', select.getAttribute('aria-label'));
    var painel = document.createElement('div');
    painel.className = 'busca-select-painel';
    painel.hidden = true;
    var campo = document.createElement('input');
    campo.type = 'search';
    campo.placeholder = select.dataset.busca && select.dataset.busca !== '1' ? select.dataset.busca : 'Buscar por código ou nome…';
    campo.setAttribute('aria-label', 'Buscar');
    var lista = document.createElement('ul');
    lista.className = 'busca-select-lista';
    lista.setAttribute('role', 'listbox');
    var vazio = document.createElement('p');
    vazio.className = 'suave busca-select-vazio';
    vazio.textContent = 'Nada encontrado.';
    vazio.hidden = true;
    painel.append(campo, lista, vazio);

    if (select.hasAttribute('data-compacto')) caixa.classList.add('compacta'); // caixa mais baixa e texto menor
    select.parentNode.insertBefore(caixa, select);
    caixa.append(botao, painel, select);
    select.classList.add('busca-select-original');
    select.tabIndex = -1;

    var itens = [];
    var marcado = -1;

    function atualizarBotao() {
      var op = select.selectedOptions[0];
      botao.textContent = op ? op.textContent : '';
      botao.classList.toggle('vazio', !op || op.value === '');
      botao.disabled = select.disabled;
      caixa.classList.toggle('travado', select.disabled);
    }

    function montarLista() {
      lista.textContent = '';
      itens = Array.prototype.map.call(select.options, function (op) {
        var li = document.createElement('li');
        li.setAttribute('role', 'option');
        li.textContent = op.textContent;
        li.dataset.valor = op.value;
        li.dataset.texto = semAcento(op.textContent + ' ' + op.value);
        if (op.selected) li.classList.add('selecionado');
        li.addEventListener('mousedown', function (e) { e.preventDefault(); escolher(op.value); });
        lista.appendChild(li);
        return li;
      });
    }

    function filtrar() {
      var termo = semAcento(campo.value.trim());
      var partes = termo.split(/\s+/).filter(Boolean);
      var visiveis = 0;
      itens.forEach(function (li) {
        var ok = partes.every(function (p) { return li.dataset.texto.indexOf(p) !== -1; });
        li.hidden = !ok;
        if (ok) visiveis++;
      });
      vazio.hidden = visiveis > 0;
      marcar(itens.findIndex(function (li) { return !li.hidden; }));
    }

    function marcar(i) {
      itens.forEach(function (li) { li.classList.remove('ativo'); });
      marcado = i;
      if (i >= 0 && itens[i]) { itens[i].classList.add('ativo'); itens[i].scrollIntoView({ block: 'nearest' }); }
    }

    function mover(passo) {
      var i = marcado;
      do { i += passo; } while (itens[i] && itens[i].hidden);
      if (itens[i]) marcar(i);
    }

    function abrir() {
      if (select.disabled) return;
      montarLista();
      painel.hidden = false;
      botao.setAttribute('aria-expanded', 'true');
      campo.value = '';
      ultimaBusca = '';
      filtrar();
      if (url) { vazio.textContent = 'Digite ao menos 2 letras do código ou da descrição.'; vazio.hidden = false; }
      var atual = itens.findIndex(function (li) { return li.classList.contains('selecionado'); });
      if (atual >= 0) marcar(atual);
      campo.focus();
    }

    function fechar() {
      painel.hidden = true;
      botao.setAttribute('aria-expanded', 'false');
    }

    function escolher(valor) {
      select.value = valor;
      select.dispatchEvent(new Event('change', { bubbles: true }));
      fechar();
      botao.focus();
    }

    botao.addEventListener('click', function () { painel.hidden ? abrir() : fechar(); });
    // Dentro de um <label>, o clique no painel seria repassado ao botão (e reabriria a lista): bloqueia esse repasse.
    painel.addEventListener('click', function (e) { if (e.target !== campo) e.preventDefault(); });
    // Busca no servidor (data-busca-url): troca as opções do <select> pelas encontradas, mantendo a escolhida.
    var url = select.dataset.buscaUrl, espera = null, ultimaBusca = '';
    function buscarNoServidor() {
      var termo = campo.value.trim();
      if (termo.length < 2) { vazio.textContent = 'Digite ao menos 2 letras do código ou da descrição.'; vazio.hidden = false; return; }
      if (termo === ultimaBusca) return;
      ultimaBusca = termo;
      vazio.textContent = 'Buscando…'; vazio.hidden = false;
      fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(termo), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : []; })
        .then(function (dados) {
          if (campo.value.trim() !== termo) return; // já digitou outra coisa
          var atual = select.selectedOptions[0];
          Array.prototype.slice.call(select.options).forEach(function (op) { if (op !== atual && op.value !== '') op.remove(); });
          dados.forEach(function (d) { if (!atual || d.valor !== atual.value) select.add(new Option(d.texto, d.valor)); });
          montarLista();
          itens.forEach(function (li) { li.hidden = li.dataset.valor === '' ; });
          var visiveis = itens.filter(function (li) { return !li.hidden; }).length;
          vazio.textContent = 'Nada encontrado.';
          vazio.hidden = visiveis > 0;
          marcar(itens.findIndex(function (li) { return !li.hidden; }));
        })
        .catch(function () { vazio.textContent = 'Não foi possível buscar agora.'; vazio.hidden = false; });
    }
    campo.addEventListener('input', function () {
      if (!url) { filtrar(); return; }
      clearTimeout(espera);
      espera = setTimeout(buscarNoServidor, 250);
    });
    campo.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); mover(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); mover(-1); }
      else if (e.key === 'Enter') { e.preventDefault(); if (itens[marcado]) escolher(itens[marcado].dataset.valor); }
      else if (e.key === 'Escape') { fechar(); botao.focus(); }
    });
    document.addEventListener('mousedown', function (e) { if (!caixa.contains(e.target)) fechar(); });
    select.addEventListener('change', atualizarBotao);
    // Outro código mudou "disabled" ou as opções: redesenha.
    new MutationObserver(atualizarBotao).observe(select, { attributes: true, attributeFilter: ['disabled'], childList: true });
    atualizarBotao();
  }

  // ---------- 2) Escolha de vários com busca ----------
  function montarEscolha(caixa) {
    if (caixa.dataset.escolhaPronta) return;
    caixa.dataset.escolhaPronta = '1';
    var busca = caixa.querySelector('[data-escolha-busca]');
    var resumo = caixa.querySelector('[data-escolha-resumo]');
    var vazio = caixa.querySelector('[data-escolha-vazio]');
    var itens = Array.prototype.slice.call(caixa.querySelectorAll('[data-escolha-item]'));

    function atualizarResumo() {
      var nomes = itens.filter(function (it) { return it.querySelector('input').checked; })
        .map(function (it) { return it.dataset.nome; });
      resumo.textContent = '';
      if (!nomes.length) {
        resumo.textContent = resumo.dataset.vazio || 'Nenhum';
        resumo.classList.add('suave');
        return;
      }
      resumo.classList.remove('suave');
      nomes.forEach(function (n) {
        var chip = document.createElement('span');
        chip.className = 'escolha-chip';
        chip.textContent = n;
        resumo.appendChild(chip);
      });
    }

    function filtrar() {
      var partes = semAcento(busca.value.trim()).split(/\s+/).filter(Boolean);
      var visiveis = 0;
      itens.forEach(function (it) {
        var ok = partes.every(function (p) { return it.dataset.texto.indexOf(p) !== -1; });
        it.hidden = !ok;
        if (ok) visiveis++;
      });
      if (vazio) vazio.hidden = visiveis > 0;
    }

    itens.forEach(function (it) {
      it.dataset.texto = semAcento(it.textContent);
      it.querySelector('input').addEventListener('change', atualizarResumo);
    });
    if (busca) {
      busca.addEventListener('input', filtrar);
      busca.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
      caixa.addEventListener('toggle', function () { if (caixa.open) busca.focus(); });
    }
    atualizarResumo();
  }

  function iniciar(raiz) {
    (raiz || document).querySelectorAll('select[data-busca]').forEach(montarBusca);
    (raiz || document).querySelectorAll('[data-escolha]').forEach(montarEscolha);
    (raiz || document).querySelectorAll('[data-abas]').forEach(function (c) { if (!c.dataset.abasProntas) { c.dataset.abasProntas = '1'; montarAbas(c); } });
  }

  window.OeeComponentes = { iniciar: iniciar };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { iniciar(); });
  else iniciar();
  // ---------- 3) Abas ----------
  function montarAbas(caixa) {
    var botoes = Array.prototype.slice.call(caixa.querySelectorAll('[data-aba]'));
    var paineis = caixa.querySelectorAll('[data-painel]');
    function abrir(nome, guardar) {
      if (!botoes.some(function (b) { return b.dataset.aba === nome; })) return false;
      botoes.forEach(function (b) {
        var ativa = b.dataset.aba === nome;
        b.classList.toggle('ativa', ativa); b.setAttribute('aria-selected', ativa ? 'true' : 'false'); b.tabIndex = ativa ? 0 : -1;
      });
      paineis.forEach(function (p) { p.hidden = p.dataset.painel !== nome; });
      if (guardar) history.replaceState(null, '', '#aba-' + nome);
      return true;
    }
    botoes.forEach(function (b, i) {
      b.addEventListener('click', function () { abrir(b.dataset.aba, true); });
      b.addEventListener('keydown', function (e) {
        var passo = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
        if (!passo) return;
        var outro = botoes[(i + passo + botoes.length) % botoes.length];
        abrir(outro.dataset.aba, true); outro.focus(); e.preventDefault();
      });
    });
    var pedida = (location.hash.match(/^#aba-([\w-]+)/) || [])[1];
    if (!pedida || !abrir(pedida, false)) abrir(caixa.dataset.abaInicial || botoes[0].dataset.aba, false);
  }
})();
