/*
 * "Pergunte para Alumbra" (box da página inicial). Grava a pergunta, chama as etapas até a resposta ficar pronta e
 * desenha a resposta: Markdown simples (títulos, listas, negrito, código, tabelas com "Baixar CSV") e gráficos em SVG
 * a partir de blocos ```grafico {json}```. Todo texto vindo da IA é escapado antes de virar HTML.
 */
(function () {
  'use strict';

  var caixa = document.querySelector('[data-assistente]');
  if (!caixa) return;

  var conversaEl = caixa.querySelector('[data-assistente-conversa]');
  var vazioEl = caixa.querySelector('[data-assistente-vazio]');
  var form = caixa.querySelector('[data-assistente-form]');
  var campo = form.querySelector('textarea');
  var botao = caixa.querySelector('[data-assistente-enviar]');
  var botaoNova = caixa.querySelector('[data-assistente-nova]');
  var restantesEl = caixa.querySelector('[data-assistente-restantes]');
  var conversaId = null;
  var ocupado = false;

  // ------------------------------------------------------------------ comunicação

  function pedir(url, corpo) {
    var opcoes = { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
    if (corpo !== undefined) {
      opcoes.method = 'POST';
      opcoes.headers['Content-Type'] = 'application/json';
      opcoes.headers['X-CSRF-TOKEN'] = caixa.dataset.csrf;
      opcoes.body = JSON.stringify(corpo);
    }
    return fetch(url, opcoes).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) {
          var msg = j.mensagem || (j.errors && j.errors.pergunta && j.errors.pergunta[0]) || j.message;
          if (r.status === 419) msg = 'A sessão expirou. Recarregue a página.';
          throw new Error(msg || 'Não foi possível falar com o portal (erro ' + r.status + ').');
        }
        return j;
      });
    });
  }

  function mostrarRestantes(n) {
    if (!restantesEl) return;
    restantesEl.textContent = (n === null || n === undefined) ? '' : (n === 1 ? 'Resta 1 pergunta hoje.' : 'Restam ' + n + ' perguntas hoje.');
  }

  function carregar() {
    pedir(caixa.dataset.urlConversa).then(function (j) {
      mostrarRestantes(j.restantes);
      if (!j.configurado) {
        adicionarAviso('O assistente ainda não foi configurado: falta a chave da API da Claude no servidor.');
        return;
      }
      conversaId = j.conversa_id;
      (j.itens || []).forEach(function (item) {
        adicionarPergunta(item.pergunta);
        var r = adicionarResposta();
        if (item.resposta) desenhar(r, item.resposta); else r.innerHTML = '<p class="suave">Sem resposta.</p>';
      });
      atualizarEstado();
    }).catch(function () { /* o box continua utilizável */ });
  }

  function enviar(texto) {
    texto = (texto || '').trim();
    if (!texto || ocupado) return;
    ocupado = true;
    atualizarEstado();
    adicionarPergunta(texto);
    var resposta = adicionarResposta();
    var progresso = mostrarProgresso(resposta);
    campo.value = '';
    ajustarAltura();

    pedir(caixa.dataset.urlPerguntar, { pergunta: texto, conversa_id: conversaId })
      .then(function (j) {
        conversaId = j.conversa_id;
        mostrarRestantes(j.restantes);
        return etapas(j.pergunta_id, progresso);
      })
      .then(function (j) {
        if (j.situacao === 'concluida') desenhar(resposta, j.resposta || '');
        else erro(resposta, j.mensagem || 'Não foi possível responder.');
      })
      .catch(function (e) { erro(resposta, e.message); })
      .then(function () {
        ocupado = false;
        atualizarEstado();
        campo.focus();
      });
  }

  function etapas(id, progresso) {
    var url = caixa.dataset.urlEtapa.replace('__ID__', id);
    return new Promise(function (resolver, rejeitar) {
      function proxima() {
        pedir(url, {}).then(function (j) {
          if (j.situacao === 'continuar') {
            (j.acoes || []).forEach(function (a) { progresso.acao(a); });
            setTimeout(proxima, j.aguardar ? 1500 : 150);
          } else {
            resolver(j);
          }
        }).catch(rejeitar);
      }
      proxima();
    });
  }

  // ------------------------------------------------------------------ conversa na tela

  function rolar() { conversaEl.scrollTop = conversaEl.scrollHeight; }

  function adicionarPergunta(texto) {
    if (vazioEl) vazioEl.hidden = true;
    var el = document.createElement('div');
    el.className = 'assistente-msg pergunta';
    el.textContent = texto;
    conversaEl.appendChild(el);
    rolar();
  }

  function adicionarResposta() {
    var el = document.createElement('div');
    el.className = 'assistente-msg resposta';
    conversaEl.appendChild(el);
    rolar();
    return el;
  }

  function adicionarAviso(texto) {
    var el = document.createElement('p');
    el.className = 'assistente-aviso';
    el.textContent = texto;
    conversaEl.appendChild(el);
  }

  function mostrarProgresso(el) {
    el.innerHTML = '<div class="assistente-pensando"><span class="assistente-pontos"><i></i><i></i><i></i></span> <span>Pensando…</span></div><ul class="assistente-acoes"></ul>';
    var lista = el.querySelector('.assistente-acoes');
    var vistas = {};
    return {
      acao: function (texto) {
        if (vistas[texto]) return;
        vistas[texto] = true;
        var li = document.createElement('li');
        li.textContent = texto + '…';
        lista.appendChild(li);
        rolar();
      }
    };
  }

  function erro(el, texto) {
    el.innerHTML = '';
    var p = document.createElement('p');
    p.className = 'erro';
    p.textContent = texto;
    el.appendChild(p);
    rolar();
  }

  function atualizarEstado() {
    botao.disabled = ocupado;
    botao.textContent = ocupado ? 'Respondendo…' : 'Perguntar';
    botaoNova.hidden = ocupado || !conversaEl.querySelector('.assistente-msg');
  }

  function ajustarAltura() {
    campo.style.height = 'auto';
    campo.style.height = Math.min(campo.scrollHeight, 180) + 'px';
  }

  form.addEventListener('submit', function (e) { e.preventDefault(); enviar(campo.value); });
  campo.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); enviar(campo.value); }
  });
  campo.addEventListener('input', ajustarAltura);
  caixa.addEventListener('click', function (e) {
    var s = e.target.closest('[data-assistente-sugestao]');
    if (s) enviar(s.textContent);
    var csv = e.target.closest('[data-baixar-csv]');
    if (csv) baixarCsv(csv.closest('.assistente-tabela').querySelector('table'));
  });
  botaoNova.addEventListener('click', function () {
    conversaId = null;
    Array.prototype.slice.call(conversaEl.querySelectorAll('.assistente-msg, .assistente-aviso')).forEach(function (n) { n.remove(); });
    if (vazioEl) vazioEl.hidden = false;
    atualizarEstado();
    campo.focus();
  });

  // ------------------------------------------------------------------ Markdown (seguro: tudo é escapado antes)

  function escapar(t) {
    return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function emLinha(t) {
    var codigos = [];
    t = escapar(t).replace(/`([^`]+)`/g, function (_, c) { codigos.push(c); return '\u0000' + (codigos.length - 1) + '\u0000'; });
    t = t.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
      .replace(/(^|[^*\w])\*([^*\n]+)\*(?!\w)/g, '$1<em>$2</em>')
      .replace(/(^|[^\w])_([^_\n]+)_(?!\w)/g, '$1<em>$2</em>');
    return t.replace(/\u0000(\d+)\u0000/g, function (_, i) { return '<code>' + codigos[+i] + '</code>'; });
  }

  function celulas(linha) {
    linha = linha.trim().replace(/^\|/, '').replace(/\|$/, '');
    return linha.split('|').map(function (c) { return c.trim(); });
  }

  function ehNumero(t) { return /^[-+]?[R$\s]*[\d.]+(,\d+)?\s*(%|h|min|un|pç|pçs)?$/i.test(t.trim()) && /\d/.test(t); }

  function tabela(linhas) {
    var cab = celulas(linhas[0]);
    var corpo = linhas.slice(2).map(celulas);
    var html = '<div class="assistente-tabela"><div class="rolagem"><table class="tabela"><thead><tr>';
    cab.forEach(function (c) { html += '<th>' + emLinha(c) + '</th>'; });
    html += '</tr></thead><tbody>';
    corpo.forEach(function (l) {
      html += '<tr>';
      cab.forEach(function (_, i) {
        var v = l[i] || '';
        html += '<td' + (ehNumero(v) ? ' class="num"' : '') + '>' + emLinha(v) + '</td>';
      });
      html += '</tr>';
    });
    return html + '</tbody></table></div><button type="button" class="botao secundario pequeno" data-baixar-csv>Baixar CSV</button></div>';
  }

  function markdown(texto) {
    var linhas = String(texto).replace(/\r\n?/g, '\n').split('\n');
    var html = '';
    var graficos = [];
    var i = 0;
    while (i < linhas.length) {
      var l = linhas[i];
      var cerca = l.match(/^\s*```\s*([\w-]*)\s*$/);
      if (cerca) {
        var bloco = [];
        i++;
        while (i < linhas.length && !/^\s*```\s*$/.test(linhas[i])) { bloco.push(linhas[i]); i++; }
        i++;
        if (cerca[1] === 'grafico') {
          graficos.push(bloco.join('\n'));
          html += '<div class="assistente-grafico" data-grafico="' + (graficos.length - 1) + '"></div>';
        } else {
          html += '<pre><code>' + escapar(bloco.join('\n')) + '</code></pre>';
        }
        continue;
      }
      if (/^\s*\|/.test(l) && i + 1 < linhas.length && /^\s*\|?\s*:?-{2,}/.test(linhas[i + 1])) {
        var t = [];
        while (i < linhas.length && /^\s*\|/.test(linhas[i])) { t.push(linhas[i]); i++; }
        html += tabela(t);
        continue;
      }
      var titulo = l.match(/^(#{1,4})\s+(.*)$/);
      if (titulo) {
        var n = Math.min(titulo[1].length + 3, 6);
        html += '<h' + n + '>' + emLinha(titulo[2]) + '</h' + n + '>';
        i++;
        continue;
      }
      if (/^\s*([-*•]|\d+[.)])\s+/.test(l)) {
        var ordenada = /^\s*\d+[.)]/.test(l);
        html += ordenada ? '<ol>' : '<ul>';
        while (i < linhas.length && /^\s*([-*•]|\d+[.)])\s+/.test(linhas[i])) {
          html += '<li>' + emLinha(linhas[i].replace(/^\s*([-*•]|\d+[.)])\s+/, '')) + '</li>';
          i++;
        }
        html += ordenada ? '</ol>' : '</ul>';
        continue;
      }
      if (/^\s*(---|\*\*\*)\s*$/.test(l)) { html += '<hr>'; i++; continue; }
      if (l.trim() === '') { i++; continue; }
      var par = [];
      while (i < linhas.length && linhas[i].trim() !== '' && !/^\s*(```|\||#{1,4}\s|[-*•]\s|\d+[.)]\s)/.test(linhas[i])) { par.push(emLinha(linhas[i])); i++; }
      if (par.length === 0) { par.push(emLinha(l)); i++; }
      html += '<p>' + par.join('<br>') + '</p>';
    }
    return { html: html, graficos: graficos };
  }

  function desenhar(el, texto) {
    var r = markdown(texto);
    el.innerHTML = r.html || '<p class="suave">(sem resposta)</p>';
    Array.prototype.forEach.call(el.querySelectorAll('[data-grafico]'), function (alvo) {
      try {
        grafico(alvo, JSON.parse(r.graficos[+alvo.dataset.grafico]));
      } catch (e) {
        alvo.innerHTML = '<p class="suave">Não foi possível desenhar o gráfico.</p>';
      }
    });
    rolar();
  }

  // ------------------------------------------------------------------ CSV

  function baixarCsv(tab) {
    var linhas = Array.prototype.map.call(tab.querySelectorAll('tr'), function (tr) {
      return Array.prototype.map.call(tr.children, function (c) {
        var v = c.textContent.trim();
        return /[";\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
      }).join(';');
    });
    var blob = new Blob(['﻿' + linhas.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'alumbra-' + new Date().toISOString().slice(0, 16).replace(/[:T]/g, '-') + '.csv';
    document.body.appendChild(a);
    a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
  }

  // ------------------------------------------------------------------ gráficos (SVG; cores das variáveis --grafico-N)

  var NS = 'http://www.w3.org/2000/svg';

  function svg(nome, atributos, texto) {
    var e = document.createElementNS(NS, nome);
    Object.keys(atributos || {}).forEach(function (k) { e.setAttribute(k, atributos[k]); });
    if (texto !== undefined) e.textContent = texto;
    return e;
  }

  function cor(i) { return 'var(--grafico-' + ((i % 6) + 1) + ')'; }

  function numeroBr(v, unidade) {
    var casas = Math.abs(v) >= 100 ? 0 : 1;
    return Number(v).toLocaleString('pt-BR', { maximumFractionDigits: casas }) + (unidade ? (unidade === '%' ? '%' : ' ' + unidade) : '');
  }

  function grafico(alvo, g) {
    var rotulos = (g.rotulos || []).slice(0, 40).map(String);
    var series = (g.series || []).slice(0, 3).map(function (s) {
      return { nome: String(s.nome || ''), valores: (s.valores || []).slice(0, rotulos.length).map(function (v) { return Number(v) || 0; }) };
    });
    if (!rotulos.length || !series.length) throw new Error('vazio');
    var unidade = g.unidade ? String(g.unidade) : '';
    var tipo = g.tipo || 'barras';

    var titulo = document.createElement('strong');
    titulo.className = 'assistente-grafico-titulo';
    titulo.textContent = g.titulo || '';
    alvo.appendChild(titulo);

    if (tipo === 'pizza') pizza(alvo, rotulos, series[0], unidade);
    else if (tipo === 'barras_horizontais') barrasHorizontais(alvo, rotulos, series, unidade);
    else eixos(alvo, rotulos, series, unidade, tipo === 'linhas');

    if (series.length > 1 || tipo === 'pizza') {
      var leg = document.createElement('div');
      leg.className = 'assistente-legenda';
      (tipo === 'pizza' ? rotulos : series.map(function (s) { return s.nome; })).forEach(function (nome, i) {
        var item = document.createElement('span');
        var marca = document.createElement('i');
        marca.style.background = cor(i);
        item.appendChild(marca);
        item.appendChild(document.createTextNode(nome));
        leg.appendChild(item);
      });
      alvo.appendChild(leg);
    }
  }

  function escala(series) {
    var todos = [0];
    series.forEach(function (s) { todos = todos.concat(s.valores); });
    var max = Math.max.apply(null, todos), min = Math.min.apply(null, todos);
    if (max === min) max = min + 1;
    var passo = Math.pow(10, Math.floor(Math.log10((max - min) / 4)));
    [1, 2, 2.5, 5, 10].some(function (m) { if ((max - min) / (passo * m) <= 5) { passo *= m; return true; } return false; });
    return { min: Math.floor(min / passo) * passo, max: Math.ceil(max / passo) * passo, passo: passo };
  }

  function eixos(alvo, rotulos, series, unidade, linhas) {
    var L = 640, A = 280, esq = 56, dir = 12, topo = 12, base = rotulos.length > 8 ? 72 : 36;
    var larg = L - esq - dir, alt = A - topo - base;
    var e = escala(series);
    var y = function (v) { return topo + alt - (v - e.min) / (e.max - e.min) * alt; };
    var raiz = svg('svg', { viewBox: '0 0 ' + L + ' ' + A, role: 'img', 'aria-label': titulo(alvo) });
    for (var v = e.min; v <= e.max + e.passo / 2; v += e.passo) {
      raiz.appendChild(svg('line', { x1: esq, x2: L - dir, y1: y(v), y2: y(v), class: 'grade' }));
      raiz.appendChild(svg('text', { x: esq - 6, y: y(v) + 4, 'text-anchor': 'end', class: 'eixo' }, numeroBr(v, unidade)));
    }
    var faixa = larg / rotulos.length;
    rotulos.forEach(function (r, i) {
      var x = esq + faixa * i + faixa / 2;
      var t = svg('text', { x: x, y: topo + alt + 16, 'text-anchor': rotulos.length > 8 ? 'end' : 'middle', class: 'eixo' }, r.length > 18 ? r.slice(0, 17) + '…' : r);
      if (rotulos.length > 8) t.setAttribute('transform', 'rotate(-40 ' + x + ' ' + (topo + alt + 16) + ')');
      raiz.appendChild(t);
    });
    if (linhas) {
      series.forEach(function (s, si) {
        var pontos = s.valores.map(function (v, i) { return (esq + faixa * i + faixa / 2) + ',' + y(v); }).join(' ');
        raiz.appendChild(svg('polyline', { points: pontos, fill: 'none', stroke: cor(si), 'stroke-width': 2.5, 'stroke-linejoin': 'round' }));
        s.valores.forEach(function (v, i) {
          var c = svg('circle', { cx: esq + faixa * i + faixa / 2, cy: y(v), r: 3.5, fill: cor(si) });
          c.appendChild(svg('title', {}, s.nome + ' · ' + rotulos[i] + ': ' + numeroBr(v, unidade)));
          raiz.appendChild(c);
        });
      });
    } else {
      var grupo = faixa * 0.72, barra = grupo / series.length, zero = y(Math.max(e.min, 0));
      series.forEach(function (s, si) {
        s.valores.forEach(function (v, i) {
          var x = esq + faixa * i + (faixa - grupo) / 2 + barra * si;
          var r = svg('rect', { x: x, y: Math.min(y(v), zero), width: Math.max(barra - 2, 1), height: Math.abs(zero - y(v)), rx: 3, fill: cor(si) });
          r.appendChild(svg('title', {}, (s.nome ? s.nome + ' · ' : '') + rotulos[i] + ': ' + numeroBr(v, unidade)));
          raiz.appendChild(r);
          if (rotulos.length * series.length <= 16) {
            raiz.appendChild(svg('text', { x: x + barra / 2 - 1, y: Math.min(y(v), zero) - 4, 'text-anchor': 'middle', class: 'valor' }, numeroBr(v, unidade)));
          }
        });
      });
    }
    alvo.appendChild(raiz);
  }

  function barrasHorizontais(alvo, rotulos, series, unidade) {
    var linha = 26 * series.length + 8, esq = 150, L = 640, A = rotulos.length * linha + 24;
    var e = escala(series), larg = L - esq - 60;
    var x = function (v) { return esq + (v - e.min) / (e.max - e.min) * larg; };
    var raiz = svg('svg', { viewBox: '0 0 ' + L + ' ' + A, role: 'img', 'aria-label': titulo(alvo) });
    rotulos.forEach(function (r, i) {
      var y0 = i * linha + 8;
      raiz.appendChild(svg('text', { x: esq - 8, y: y0 + linha / 2 + 2, 'text-anchor': 'end', class: 'eixo' }, r.length > 24 ? r.slice(0, 23) + '…' : r));
      series.forEach(function (s, si) {
        var v = s.valores[i] || 0, x0 = x(Math.max(e.min, 0));
        var b = svg('rect', { x: Math.min(x(v), x0), y: y0 + si * 26, width: Math.max(Math.abs(x(v) - x0), 1), height: 20, rx: 3, fill: cor(si) });
        b.appendChild(svg('title', {}, (s.nome ? s.nome + ' · ' : '') + r + ': ' + numeroBr(v, unidade)));
        raiz.appendChild(b);
        raiz.appendChild(svg('text', { x: Math.max(x(v), x0) + 6, y: y0 + si * 26 + 14, class: 'valor' }, numeroBr(v, unidade)));
      });
    });
    alvo.appendChild(raiz);
  }

  function pizza(alvo, rotulos, serie, unidade) {
    var total = serie.valores.reduce(function (a, b) { return a + Math.max(b, 0); }, 0) || 1;
    var raiz = svg('svg', { viewBox: '0 0 240 240', role: 'img', 'aria-label': titulo(alvo), class: 'pizza' });
    var ang = -Math.PI / 2, R = 100, r = 58, c = 120;
    serie.valores.forEach(function (v, i) {
      var parte = Math.max(v, 0) / total, fim = ang + parte * Math.PI * 2;
      if (parte <= 0) return;
      if (parte >= 0.9999) fim = ang + Math.PI * 2 - 0.0001;
      var grande = fim - ang > Math.PI ? 1 : 0;
      var p = function (a, raio) { return (c + raio * Math.cos(a)) + ' ' + (c + raio * Math.sin(a)); };
      var d = 'M ' + p(ang, R) + ' A ' + R + ' ' + R + ' 0 ' + grande + ' 1 ' + p(fim, R) + ' L ' + p(fim, r) + ' A ' + r + ' ' + r + ' 0 ' + grande + ' 0 ' + p(ang, r) + ' Z';
      var fatia = svg('path', { d: d, fill: cor(i), class: 'fatia' });
      fatia.appendChild(svg('title', {}, rotulos[i] + ': ' + numeroBr(v, unidade) + ' (' + numeroBr(parte * 100, '%') + ')'));
      raiz.appendChild(fatia);
      ang = fim;
    });
    raiz.appendChild(svg('text', { x: c, y: c + 6, 'text-anchor': 'middle', class: 'total' }, numeroBr(total, unidade)));
    alvo.appendChild(raiz);
  }

  function titulo(alvo) {
    var t = alvo.querySelector('.assistente-grafico-titulo');
    return t ? t.textContent : 'Gráfico';
  }

  carregar();
  atualizarEstado();
})();
