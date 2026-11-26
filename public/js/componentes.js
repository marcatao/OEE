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
 * 4) [data-calculadora-pu="0.025"] — calculadora do apontamento de perdas: campos [data-campo="quantidade"] e
 *    [data-campo="peso"]; digitar um calcula o outro pelo PU (kg por peça), em formato brasileiro; [data-conta] mostra a
 *    conta. Sem PU (atributo vazio), só a quantidade vale. O botão [data-confirmar-perda] pede confirmação com a conta.
 *
 * 5) [data-assinatura] — quadro de assinatura com o dedo (celular) ou o mouse: um <canvas> e um campo escondido
 *    [data-assinatura-campo] que recebe a imagem (PNG em base64) ao soltar; [data-assinatura-limpar] apaga. O formulário
 *    não é enviado com o quadro em branco. Mesma ideia do quadro da avaliação da OS no aplicativo.
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
    (raiz || document).querySelectorAll('[data-assinatura]').forEach(montarAssinatura);
    (raiz || document).querySelectorAll('[data-alternar-tema]').forEach(function (b) { if (!b.dataset.temaPronto) { b.dataset.temaPronto = '1'; montarTema(b); } });
    (raiz || document).querySelectorAll('[data-notificacoes]').forEach(function (n) { if (!n.dataset.notifPronto) { n.dataset.notifPronto = '1'; montarNotificacoes(n); } });
    (raiz || document).querySelectorAll('[data-grafico-ciclos]').forEach(function (g) { if (!g.dataset.ciclosPronto) { g.dataset.ciclosPronto = '1'; montarGraficoCiclos(g); } });
    (raiz || document).querySelectorAll('[data-calculadora-pu]').forEach(function (f) { if (!f.dataset.calcPronta) { f.dataset.calcPronta = '1'; montarCalculadoraPu(f); } });
  }

  // ---------- 4) Calculadora de perdas pelo PU ----------
  function lerNumero(texto) {
    texto = (texto || '').trim();
    if (texto.indexOf(',') >= 0) texto = texto.replace(/\./g, '').replace(',', '.');
    var n = parseFloat(texto);
    return isFinite(n) && n > 0 ? n : 0;
  }
  function numeroBr(n, casas) {
    return n.toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: casas });
  }
  function montarCalculadoraPu(form) {
    var pu = lerNumero(form.dataset.calculadoraPu);
    var qtd = form.querySelector('[data-campo="quantidade"]');
    var peso = form.querySelector('[data-campo="peso"]');
    var conta = form.querySelector('[data-conta]');
    var botao = form.querySelector('[data-confirmar-perda]');
    var textoInicial = conta ? conta.textContent : '';
    function mostrar() {
      var q = lerNumero(qtd.value), p = peso ? lerNumero(peso.value) : 0;
      if (!conta) return;
      conta.classList.toggle('perda-conta-ok', q > 0);
      conta.textContent = q > 0 ? (numeroBr(q, 4) + ' peças' + (pu ? ' × ' + numeroBr(pu, 6) + ' kg = ' + numeroBr(p, 4) + ' kg' : '')) : textoInicial;
    }
    qtd.addEventListener('input', function () {
      var q = lerNumero(qtd.value);
      if (peso && pu) peso.value = q > 0 ? numeroBr(q * pu, 4) : '';
      mostrar();
    });
    if (peso) peso.addEventListener('input', function () {
      var p = lerNumero(peso.value);
      if (pu) qtd.value = p > 0 ? numeroBr(p / pu, 4) : '';
      mostrar();
    });
    form.addEventListener('submit', function (e) {
      var q = lerNumero(qtd.value);
      if (q <= 0) { e.preventDefault(); qtd.focus(); if (conta) { conta.textContent = 'Informe a quantidade de peças' + (pu ? ' ou o peso' : '') + '.'; conta.classList.add('texto-alerta'); } return; }
      var p = peso ? lerNumero(peso.value) : 0;
      if (!confirm('Apontar perda de ' + numeroBr(q, 4) + ' peças' + (p > 0 ? ' (' + numeroBr(p, 4) + ' kg)' : '') + '?')) e.preventDefault();
    });
    mostrar();
  }

  // ---------- 5) Quadro de assinatura ----------
  function montarAssinatura(caixa) {
    if (caixa.dataset.assinaturaPronta) return;
    caixa.dataset.assinaturaPronta = '1';
    var canvas = caixa.querySelector('canvas'), campo = caixa.querySelector('[data-assinatura-campo]');
    var ctx = canvas.getContext('2d'), desenhando = false, riscou = false, ultimo = null, tinta = 'black';
    function ajustar() {
      var r = canvas.getBoundingClientRect(), escala = window.devicePixelRatio || 1;
      if (!r.width) return;
      canvas.width = Math.round(r.width * escala); canvas.height = Math.round(r.height * escala);
      ctx.setTransform(escala, 0, 0, escala, 0, 0);
      tinta = getComputedStyle(canvas).color || 'black';
      ctx.lineWidth = 2.2; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = tinta;
      limpar();
    }
    function ponto(e) { var r = canvas.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }
    function limpar() {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      riscou = false; campo.value = ''; caixa.classList.remove('assinado');
    }
    canvas.addEventListener('pointerdown', function (e) {
      e.preventDefault(); desenhando = true; ultimo = ponto(e);
      try { canvas.setPointerCapture(e.pointerId); } catch (x) {}
      ctx.beginPath(); ctx.arc(ultimo.x, ultimo.y, 1.1, 0, Math.PI * 2); ctx.fillStyle = tinta; ctx.fill();
    });
    canvas.addEventListener('pointerdown', function (e) { canvas.dispatchEvent(new PointerEvent('pointermove', e)); });
    canvas.addEventListener('pointermove', function (e) {
      if (!desenhando) return;
      e.preventDefault();
      var p = ponto(e);
      ctx.beginPath(); ctx.moveTo(ultimo.x, ultimo.y); ctx.lineTo(p.x, p.y); ctx.stroke();
      ultimo = p; riscou = true;
    });
    function soltar() {
      if (!desenhando) return;
      desenhando = false;
      if (riscou) { campo.value = canvas.toDataURL('image/png'); caixa.classList.add('assinado'); }
    }
    canvas.addEventListener('pointerup', soltar);
    canvas.addEventListener('pointercancel', soltar);
    canvas.addEventListener('pointerleave', soltar);
    var botao = caixa.querySelector('[data-assinatura-limpar]');
    if (botao) botao.addEventListener('click', limpar);
    var form = caixa.closest('form');
    if (form) form.addEventListener('submit', function (e) {
      if (!campo.value) { e.preventDefault(); alert('Assine no quadro antes de confirmar.'); }
    });
    window.addEventListener('resize', function () { if (!riscou) ajustar(); });
    // Quadro dentro de um bloco que abre e fecha (<details>): só tem tamanho depois de aberto.
    var bloco = caixa.closest('details');
    if (bloco) bloco.addEventListener('toggle', function () { if (bloco.open && !riscou) ajustar(); });
    ajustar();
  }

  window.OeeComponentes = { iniciar: iniciar };
  // ---------- Tema claro/escuro (botão do topo) ----------
  // Sem escolha o portal segue o sistema. O botão troca para o tema oposto ao que está na tela e guarda a escolha;
  // voltar ao tema do sistema apaga a escolha (passa a seguir o sistema de novo). Aviso 'oee-tema' para os gráficos.
  function montarTema(botao) {
    var raiz = document.documentElement, escuroSistema = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    function atual() { return raiz.dataset.tema || (escuroSistema && escuroSistema.matches ? 'escuro' : 'claro'); }
    function rotular() {
      var texto = atual() === 'escuro' ? 'Mudar para o tema claro' : 'Mudar para o tema escuro';
      botao.title = texto; botao.setAttribute('aria-label', texto);
    }
    botao.addEventListener('click', function () {
      var novo = atual() === 'escuro' ? 'claro' : 'escuro', sistema = escuroSistema && escuroSistema.matches ? 'escuro' : 'claro';
      try {
        if (novo === sistema) { delete raiz.dataset.tema; localStorage.removeItem('oee-tema'); }
        else { raiz.dataset.tema = novo; localStorage.setItem('oee-tema', novo); }
      } catch (e) { raiz.dataset.tema = novo; }
      rotular(); document.dispatchEvent(new CustomEvent('oee-tema'));
    });
    if (escuroSistema) escuroSistema.addEventListener('change', rotular);
    rotular();
  }

  // ---------- Notificações (sino do topo, App\Services\Notificacoes\NotificacoesUsuario) ----------
  // Atualiza a cada minuto; o que é NOVO (id ainda não visto neste navegador) vira pop-up do Windows (Notification API)
  // quando a página não está à vista (navegador minimizado ou outra aba). Na 1ª vez só guarda o que já existe.
  function montarNotificacoes(caixa) {
    var botao = caixa.querySelector('[data-notificacoes-botao]'), painel = caixa.querySelector('[data-notificacoes-painel]');
    var lista = caixa.querySelector('[data-notificacoes-lista]'), contador = caixa.querySelector('[data-notificacoes-contador]');
    var ativar = caixa.querySelector('[data-notificacoes-ativar]'), dica = caixa.querySelector('[data-notificacoes-dica]');
    var CHAVE = 'oee-notificacoes-vistas', suporta = 'Notification' in window;
    function lerVistas() { try { return JSON.parse(localStorage.getItem(CHAVE) || 'null'); } catch (e) { return null; } }
    function gravarVistas(ids) { try { localStorage.setItem(CHAVE, JSON.stringify(ids.slice(-300))); } catch (e) {} }

    function abrir(sim) {
      painel.hidden = !sim; botao.setAttribute('aria-expanded', String(sim));
      if (sim) { atualizarAtivar(); var primeiro = painel.querySelector('a, button:not([hidden])'); if (primeiro) primeiro.focus(); }
    }
    botao.addEventListener('click', function () { abrir(painel.hidden); });
    document.addEventListener('click', function (e) { if (!painel.hidden && !caixa.contains(e.target)) abrir(false); });
    caixa.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !painel.hidden) { abrir(false); botao.focus(); } });

    function atualizarAtivar() {
      if (!suporta) { ativar.hidden = true; dica.hidden = false; dica.textContent = 'Este navegador não mostra avisos no Windows.'; return; }
      ativar.hidden = Notification.permission !== 'default';
      dica.hidden = Notification.permission !== 'denied';
      if (Notification.permission === 'denied') dica.textContent = 'Os avisos no Windows estão bloqueados para este site (libere no cadeado da barra de endereço).';
    }
    ativar.addEventListener('click', function () {
      Notification.requestPermission().then(function () {
        atualizarAtivar();
        if (Notification.permission === 'granted') new Notification('Avisos ligados', { body: 'Você vai ver aqui as notificações do portal, mesmo com o navegador minimizado.', icon: caixa.dataset.icone, tag: 'oee-teste' });
      });
    });

    function desenhar(itens) {
      contador.hidden = itens.length === 0; contador.textContent = itens.length > 99 ? '99+' : String(itens.length);
      botao.setAttribute('aria-label', 'Notificações' + (itens.length ? ' (' + itens.length + ' novas)' : ''));
      lista.textContent = '';
      if (!itens.length) { var v = document.createElement('li'); v.className = 'notificacoes-vazia suave'; v.textContent = 'Nenhuma notificação.'; lista.appendChild(v); return; }
      itens.forEach(function (n) {
        var li = document.createElement('li'), a = document.createElement('a'), s = document.createElement('strong'), sp = document.createElement('span');
        if (n.urgente) li.className = 'urgente';
        a.href = n.url; s.textContent = n.titulo; sp.textContent = n.texto;
        a.appendChild(s); a.appendChild(sp); li.appendChild(a); lista.appendChild(li);
      });
    }

    function avisar(itens) {
      var vistas = lerVistas(), ids = itens.map(function (n) { return n.id; });
      if (vistas !== null && suporta && Notification.permission === 'granted' && (document.visibilityState !== 'visible' || !document.hasFocus())) {
        itens.filter(function (n) { return vistas.indexOf(n.id) < 0; }).slice(0, 5).forEach(function (n) {
          var pop = new Notification(n.titulo, { body: n.texto, icon: caixa.dataset.icone, tag: 'oee-' + n.id, requireInteraction: !!n.urgente });
          pop.onclick = function () { window.focus(); location.href = n.url; pop.close(); };
        });
      }
      // Com a página à vista o usuário já vê o contador: conta como vista (sem pop-up depois).
      gravarVistas((vistas || []).filter(function (id) { return ids.indexOf(id) >= 0; }).concat(ids.filter(function (id) { return (vistas || []).indexOf(id) < 0; })));
    }

    function buscar() {
      fetch(caixa.dataset.url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
        .then(function (d) { desenhar(d.itens || []); avisar(d.itens || []); })
        .catch(function () {});
    }

    var iniciais = [];
    try { iniciais = JSON.parse(caixa.querySelector('[data-notificacoes-dados]').textContent || '[]'); } catch (e) {}
    avisar(iniciais);
    setInterval(buscar, 60000);
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') buscar(); });
  }

  // ---------- Gráfico de ciclos da máquina (Dashboard por máquina, App\Services\CiclosIot) ----------
  // Dados: [ts_unix, contador, peças, intervalo s, ciclo por peça s, nível 0 normal | 1 micro | 2 parada grande].
  // Uma barra por mudança do contador; a escala vem dos ciclos normais e microparadas (percentil 98), então as paradas
  // grandes (vermelhas) e o que passa do topo são CORTADOS e ganham uma marca, para não esconder os ciclos normais.
  // Com mais barras que pixels, cada coluna mostra a maior barra dela (e a cor mais grave).
  function montarGraficoCiclos(caixa) {
    var todos = JSON.parse(caixa.querySelector('script[type="application/json"]').textContent || '[]');
    var canvas = caixa.querySelector('canvas'), dica = caixa.querySelector('.ciclos-dica');
    var cartao = caixa.closest('.ciclos-iot') || document, seletorHora = cartao.querySelector('[data-ciclos-hora]');
    var fuso = caixa.dataset.fuso, dados = todos, colunas = [], escala = 1;
    var fHora = new Intl.DateTimeFormat('pt-BR', { timeZone: fuso, hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });
    var fH = new Intl.DateTimeFormat('pt-BR', { timeZone: fuso, hour: '2-digit', hourCycle: 'h23' });
    function hora(ts) { return fHora.format(new Date(ts * 1000)); }
    function horaDe(ts) { return parseInt(fH.format(new Date(ts * 1000)), 10); }
    function seg(s) { return s === null ? '—' : numeroBr(s, s < 10 ? 3 : 1) + ' s'; }
    function tempo(s) { if (s === null) return '—'; if (s < 60) return seg(s); var m = Math.floor(s / 60); return m >= 60 ? Math.floor(m / 60) + ' h ' + (m % 60) + ' min' : m + ' min ' + Math.round(s % 60) + ' s'; }
    function cor(nome) { return getComputedStyle(caixa).getPropertyValue(nome).trim(); }
    var NIVEL = ['Ciclo normal', 'Microparada', 'Parada grande'];

    function desenhar() {
      var largura = caixa.clientWidth, altura = Math.max(220, Math.min(340, Math.round(largura * 0.28)));
      var dpr = window.devicePixelRatio || 1;
      canvas.width = largura * dpr; canvas.height = altura * dpr; canvas.style.width = largura + 'px'; canvas.style.height = altura + 'px';
      var ctx = canvas.getContext('2d'); ctx.setTransform(dpr, 0, 0, dpr, 0, 0); ctx.clearRect(0, 0, largura, altura);
      var esq = 56, dir = 8, topo = 16, base = 26, w = largura - esq - dir, h = altura - topo - base;
      var cores = [cor('--primaria'), cor('--alerta'), cor('--erro')], texto = cor('--suave'), grade = cor('--borda');
      ctx.font = '11px ' + getComputedStyle(document.body).fontFamily; ctx.textBaseline = 'middle';
      if (!dados.length) { ctx.fillStyle = texto; ctx.fillText('Nenhuma mudança do contador nesta hora.', esq, topo + h / 2); colunas = []; return; }
      // Escala: percentil 98 dos ciclos NORMAIS (+ 15%); microparadas e paradas grandes passam do topo e são cortadas.
      var vals = dados.filter(function (d) { return d[5] === 0 && d[4] !== null; }).map(function (d) { return d[4]; }).sort(function (a, b) { return a - b; });
      escala = vals.length ? vals[Math.min(vals.length - 1, Math.floor(vals.length * 0.98))] * 1.15 : 1;
      if (!(escala > 0)) escala = 1;
      // Linhas de grade e rótulos do eixo Y.
      ctx.strokeStyle = grade; ctx.fillStyle = texto; ctx.lineWidth = 1; ctx.textAlign = 'right';
      for (var i = 0; i <= 4; i++) {
        var y = topo + h - h * i / 4; ctx.beginPath(); ctx.moveTo(esq, Math.round(y) + .5); ctx.lineTo(esq + w, Math.round(y) + .5); ctx.stroke();
        ctx.fillText(seg(escala * i / 4), esq - 6, y);
      }
      // Colunas: uma por barra ou, com barras demais, uma por pixel.
      var n = dados.length, porPixel = n > w, total = porPixel ? Math.floor(w) : n, larguraBarra = w / total;
      colunas = [];
      for (var c = 0; c < total; c++) {
        var de = porPixel ? Math.floor(c * n / total) : c, ate = porPixel ? Math.max(de + 1, Math.floor((c + 1) * n / total)) : c + 1;
        var maior = null, nivel = 0;
        for (var k = de; k < ate; k++) { var d = dados[k]; nivel = Math.max(nivel, d[5]); if (d[4] !== null && (maior === null || d[4] > maior)) maior = d[4]; }
        colunas.push({ x: esq + c * larguraBarra, de: de, ate: ate });
        if (maior === null) continue;
        var cortada = nivel === 2 || maior > escala, alt = cortada ? h : Math.max(1, h * maior / escala);
        var x = esq + c * larguraBarra, bw = Math.max(nivel === 2 ? 3 : 1, larguraBarra - (larguraBarra > 3 ? 1 : 0));
        ctx.fillStyle = cores[nivel]; ctx.fillRect(x, topo + h - alt, bw, alt);
        if (nivel === 2) { // parada grande: barra cortada com um triângulo vermelho no topo
          ctx.fillStyle = cores[2]; ctx.beginPath();
          ctx.moveTo(x + bw / 2, topo - 9); ctx.lineTo(x + bw / 2 - 4, topo - 2); ctx.lineTo(x + bw / 2 + 4, topo - 2); ctx.fill();
        }
      }
      // Eixo X: hora cheia onde a hora muda.
      ctx.fillStyle = texto; ctx.textAlign = 'center'; var ultimaHora = null, ultimoX = -99;
      colunas.forEach(function (col) {
        var hh = horaDe(dados[col.de][0]);
        if (hh !== ultimaHora) { ultimaHora = hh; if (col.x - ultimoX > 34) { ctx.fillText(String(hh).padStart(2, '0') + 'h', col.x, topo + h + 13); ultimoX = col.x; } }
      });
    }

    function filtrar() {
      var hh = seletorHora && seletorHora.value !== '' ? parseInt(seletorHora.value, 10) : null;
      dados = hh === null ? todos : todos.filter(function (d) { return horaDe(d[0]) === hh; });
      desenhar();
    }

    canvas.addEventListener('pointerdown', function (e) { canvas.dispatchEvent(new PointerEvent('pointermove', e)); });
    canvas.addEventListener('pointermove', function (e) {
      var r = canvas.getBoundingClientRect(), x = e.clientX - r.left, col = null;
      for (var i = colunas.length - 1; i >= 0; i--) { if (colunas[i].x <= x) { col = colunas[i]; break; } }
      if (!col) { dica.hidden = true; return; }
      var linhas;
      if (col.ate - col.de === 1) {
        var d = dados[col.de];
        linhas = ['<strong>' + hora(d[0]) + '</strong> · ' + NIVEL[d[5]] + (d[5] ? ': <strong>' + tempo(d[3]) + '</strong> sem produzir' : ''), 'Ciclo por peça: <strong>' + seg(d[4]) + '</strong>',
          'Intervalo desde a mudança anterior: ' + tempo(d[3]), 'Contador (' + caixa.dataset.canal + '): ' + numeroBr(d[1], 0) + ' (+' + d[2] + ')'];
      } else {
        var grupo = dados.slice(col.de, col.ate), v = grupo.map(function (g) { return g[4]; }).filter(function (g) { return g !== null; });
        linhas = ['<strong>' + hora(grupo[0][0]) + ' – ' + hora(grupo[grupo.length - 1][0]) + '</strong> · ' + grupo.length + ' ciclos',
          'Menor / maior: ' + seg(v.length ? Math.min.apply(null, v) : null) + ' / ' + seg(v.length ? Math.max.apply(null, v) : null),
          'Escolha uma hora para ver ciclo a ciclo.'];
      }
      dica.innerHTML = linhas.join('<br>'); dica.hidden = false;
      var px = Math.min(x + 12, caixa.clientWidth - dica.offsetWidth - 4);
      dica.style.left = Math.max(0, px) + 'px'; dica.style.top = Math.max(0, e.clientY - r.top - dica.offsetHeight - 10) + 'px';
    });
    canvas.addEventListener('pointerleave', function () { dica.hidden = true; });
    if (seletorHora) seletorHora.addEventListener('change', filtrar);
    var botaoCsv = cartao.querySelector('[data-ciclos-csv]');
    if (botaoCsv) botaoCsv.addEventListener('click', function () {
      var linhas = ['Hora;ts_unix;' + caixa.dataset.canal + ';Pecas;Intervalo (s);Ciclo por peca (s);Classificacao'];
      todos.forEach(function (d) { linhas.push([hora(d[0]), d[0], String(d[1]).replace('.', ','), d[2], d[3] === null ? '' : d[3], d[4] === null ? '' : String(d[4]).replace('.', ','), NIVEL[d[5]]].join(';')); });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob(['﻿' + linhas.join('\r\n')], { type: 'text/csv;charset=utf-8' }));
      a.download = caixa.dataset.arquivo; document.body.appendChild(a); a.click(); a.remove();
    });
    var espera; window.addEventListener('resize', function () { clearTimeout(espera); espera = setTimeout(desenhar, 150); });
    if (window.matchMedia) window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', desenhar);
    document.addEventListener('oee-tema', desenhar);
    desenhar();
  }

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
    // Link para #aba-x na mesma página (ex.: o cartão "Perda da OP") abre a aba.
    window.addEventListener('hashchange', function () {
      var nome = (location.hash.match(/^#aba-([\w-]+)/) || [])[1];
      if (nome && abrir(nome, false)) caixa.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }
})();
