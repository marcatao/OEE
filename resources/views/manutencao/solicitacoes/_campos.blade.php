{{-- Campos da SS (abertura, edição e revisão do aprovador no detalhe), em etapas: para quem → onde → o que → detalhes.
     Variáveis: $ss, $unidadesSs, $maquinas, $ferramentas, $itensTi, $areas, $centros, $ccUsuario, $sugestoes. Precisa estar dentro de um <form>. --}}
@use('App\Models\Manutencao\SolicitacaoServico')
@php($unidadeAtual = (string) old('unidade', $ss->unidade ?: (count($unidadesSs) === 1 ? $unidadesSs[0] : '')))
@php($ferramentas ??= collect())
@php($itensTi ??= collect())
@php($recursoAtual = old('recurso', old('ferramenta_id', $ss->ferramenta_id) ? 'ferramenta' : (old('item_ti_id', $ss->item_ti_id) ? 'item_ti' : 'maquina')))
    <input type="hidden" name="apontamento_estado_id" value="{{ old('apontamento_estado_id', $ss->apontamento_estado_id) }}">
    <input type="hidden" name="ordem_producao_id" value="{{ old('ordem_producao_id', $ss->ordem_producao_id) }}">

    <fieldset class="ss-etapa">
      <legend><span class="ss-passo">1</span> Para quem é o serviço</legend>
      <p class="suave ss-ajuda">Escolha a unidade e a área que vai executar. Só aparecem as unidades liberadas para você.</p>
      <div class="campos ss-campos alinhar-topo">
        <label class="largo-2">Unidade
          <select name="unidade" id="ss-unidade" required>
            @if (count($unidadesSs) !== 1)<option value="">Escolha a unidade…</option>@endif
            @foreach ($unidadesSs as $un)<option value="{{ $un }}" @selected($unidadeAtual === $un)>{{ config('oee.unidades')[$un] ?? $un }}</option>@endforeach
          </select>
        </label>
        <label class="largo-2">Área executante
          <select name="area_executante_id" id="ss-area" required>
            <option value="">Escolha a área…</option>
            @foreach ($areas as $a)
              <option value="{{ $a->id }}" data-unidade="{{ $a->unidade }}" @selected((int) old('area_executante_id', $ss->area_executante_id) === $a->id)>{{ $a->nome }}</option>
            @endforeach
          </select>
          <small id="ss-area-info" class="suave"></small>
        </label>
      </div>
    </fieldset>

    <fieldset class="ss-etapa">
      <legend><span class="ss-passo">2</span> Onde</legend>
      <p class="suave ss-ajuda">Com máquina, o centro de custo vem do cadastro dela. Sem máquina (ou para ferramenta/molde ou item de TI), informe o centro de custo.</p>
      {{-- O chamado é para: máquina (sempre) ou, nas unidades que aceitam, ferramenta/molde ou item do inventário de TI. --}}
      <div class="ss-recurso" id="ss-recurso" role="radiogroup" aria-label="O chamado é para" hidden>
        <label data-unidades="*"><input type="radio" name="recurso" value="maquina" @checked(! in_array($recursoAtual, ['ferramenta', 'item_ti'], true))> Máquina / equipamento</label>
        <label data-unidades="{{ implode(',', config('oee.os_unidades_ferramenta')) }}"><input type="radio" name="recurso" value="ferramenta" @checked($recursoAtual === 'ferramenta')> Ferramenta / molde</label>
        <label data-unidades="{{ implode(',', config('oee.os_unidades_item_ti')) }}"><input type="radio" name="recurso" value="item_ti" @checked($recursoAtual === 'item_ti')> Item do inventário de TI</label>
      </div>
      <div class="campos ss-campos alinhar-topo">
        <label class="largo-2" id="ss-item-campo" hidden>Item do inventário de TI
          <select name="item_ti_id" id="ss-item" data-busca="Buscar item por patrimônio ou descrição…">
            <option value="">Escolha o item…</option>
            @foreach ($itensTi as $it)
              <option value="{{ $it->id }}" data-unidade="{{ $it->unidade }}" data-cc="{{ trim((string) $it->centro_custo) }}" @selected((int) old('item_ti_id', $ss->item_ti_id) === $it->id)>{{ $it->codigo() }} — {{ $it->descricao }}{{ (int) $it->responsavel_id === (int) auth()->id() ? ' (está com você)' : '' }}</option>
            @endforeach
          </select>
          <small id="ss-item-info" class="suave"></small>
        </label>
        <label class="largo-2" id="ss-ferramenta-campo" hidden>Ferramenta / molde
          <select name="ferramenta_id" id="ss-ferramenta" data-busca="Buscar ferramenta/molde por código ou descrição…">
            <option value="">Escolha a ferramenta/molde…</option>
            @foreach ($ferramentas as $f)
              <option value="{{ $f->id }}" data-unidade="{{ $f->unidade }}" @selected((int) old('ferramenta_id', $ss->ferramenta_id) === $f->id)>{{ $f->codigo() }} — {{ trim($f->h4_descri) }}</option>
            @endforeach
          </select>
          <small id="ss-ferramenta-info" class="suave"></small>
        </label>
        <label class="largo-2" id="ss-maquina-campo">Máquina / equipamento
          <select name="maquina_id" id="ss-maquina" data-busca="Buscar máquina por código ou nome…">
            <option value="" data-cc="">Sem máquina (serviço geral)</option>
            @foreach ($maquinas as $m)
              <option value="{{ $m->id }}" data-cc="{{ trim((string) $m->centro_custo) }}" data-unidade="{{ $m->unidade }}" @selected((int) old('maquina_id', $ss->maquina_id) === $m->id)>{{ $m->codigo }} — {{ $m->nome }}</option>
            @endforeach
          </select>
          <small id="ss-maquina-info" class="suave"></small>
        </label>
        <label class="largo-2">Centro de custo
          @php($ccAtual = old('centro_custo', trim((string) $ss->centro_custo) ?: (string) $ccUsuario))
          <select name="centro_custo" id="ss-cc" data-busca="Buscar centro de custo por código ou nome…">
            <option value="">Escolha o centro de custo…</option>
            @foreach ($centros as $c)<option value="{{ $c->codigo() }}" @selected($ccAtual === $c->codigo())>{{ $c->codigo() }} — {{ $c->descricao() }}</option>@endforeach
          </select>
          <small id="ss-cc-info" class="suave"></small>
        </label>
      </div>
    </fieldset>

    <fieldset class="ss-etapa">
      <legend><span class="ss-passo">3</span> O que precisa</legend>
      <div class="campos ss-campos">
        <label class="largo-2">Título resumido
          <input name="titulo" id="ss-titulo" required maxlength="120" value="{{ old('titulo', $ss->titulo) }}" placeholder="Ex.: Vazamento de óleo no cilindro da injetora">
        </label>
        @if ($sugestoes->isNotEmpty())
          {{-- Sugestões preenchem SÓ o título (a descrição da etapa 4 é independente). --}}
          <div class="ss-sugestoes" role="group" aria-label="Sugestões para o título"><small class="suave">Sugestões para o título (clique para usar):</small>
            @foreach ($sugestoes as $s)<button type="button" class="ss-chip" data-sugestao="{{ $s }}">{{ $s }}</button>@endforeach
          </div>
        @endif
        <label>Tipo
          <select name="tipo" required>
            @foreach (SolicitacaoServico::TIPOS as $valor => $rotulo)<option value="{{ $valor }}" @selected(old('tipo', $ss->tipo) === $valor)>{{ $rotulo }}</option>@endforeach
          </select>
        </label>
        <label>Prioridade
          <select name="prioridade" required>
            @foreach (SolicitacaoServico::PRIORIDADES as $valor => $rotulo)<option value="{{ $valor }}" @selected(old('prioridade', $ss->prioridade) === $valor)>{{ $rotulo }}</option>@endforeach
          </select>
        </label>
      </div>
      <div class="ss-alertas">
        <label class="ss-alerta ss-alerta-parada">
          <input type="checkbox" name="maquina_parada" value="1" @checked(old('maquina_parada', $ss->maquina_parada))>
          <span class="ss-alerta-icone">@include('layouts.partials.icone', ['nome' => 'alerta'])</span>
          <span><strong>Máquina parada</strong><small>O problema parou a máquina ou o equipamento.</small></span>
        </label>
        <label class="ss-alerta ss-alerta-risco">
          <input type="checkbox" name="risco_integridade" value="1" @checked(old('risco_integridade', $ss->risco_integridade))>
          <span class="ss-alerta-icone">@include('layouts.partials.icone', ['nome' => 'alerta'])</span>
          <span><strong>Oferece risco à integridade</strong><small>Risco para pessoas, para o meio ambiente ou de dano ao equipamento.</small></span>
        </label>
      </div>
    </fieldset>

    <fieldset class="ss-etapa">
      <legend><span class="ss-passo">4</span> Conte o problema ou o serviço</legend>
      <textarea name="descricao" rows="6" maxlength="2000" required id="ss-descricao" placeholder="O que está acontecendo, desde quando, o que já foi feito…">{{ old('descricao', $ss->descricao) }}</textarea>
    </fieldset>
<script>
  (function () {
    var ccUsuario = @json((string) $ccUsuario);
    var unidade = document.getElementById('ss-unidade'), area = document.getElementById('ss-area'), areaInfo = document.getElementById('ss-area-info');
    var maquina = document.getElementById('ss-maquina'), cc = document.getElementById('ss-cc'), info = document.getElementById('ss-cc-info');
    var maquinaInfo = document.getElementById('ss-maquina-info');
    function temOpcao(v) { return v && Array.prototype.some.call(cc.options, function (o) { return o.value === v; }); }

    // Máquina com CC: usa o dela e trava (o servidor também força). Sem máquina ou máquina sem CC: o do solicitante
    // (se tiver) e libera; sem nenhum, é obrigatório escolher.
    function aplicarMaquina(inicio) {
      var ccMaq = maquina.selectedOptions[0] ? maquina.selectedOptions[0].dataset.cc : '';
      if (ccMaq) {
        if (!temOpcao(ccMaq)) { var o = new Option(ccMaq + ' (da máquina)', ccMaq); cc.add(o); }
        cc.value = ccMaq; cc.disabled = true; cc.required = false;
        info.textContent = 'Centro de custo do cadastro da máquina.';
      } else {
        if (cc.disabled || !inicio) cc.value = temOpcao(ccUsuario) ? ccUsuario : (inicio ? cc.value : '');
        cc.disabled = false; cc.required = true;
        info.textContent = cc.value && cc.value === ccUsuario ? 'Centro de custo do seu cadastro.' : (cc.value ? '' : 'Obrigatório: busque pelo código ou nome.');
      }
      cc.dispatchEvent(new Event('change'));
    }

    // Lista só o que é da unidade escolhida (e o cadastrado para "Todas"). Guarda as opções para poder voltar.
    function filtro(select, vazio) {
      var todas = Array.prototype.slice.call(select.options).filter(function (o) { return o.value !== ''; });
      return function (un, inicio) {
        var atual = select.value;
        todas.forEach(function (o) { if (o.parentNode) o.remove(); });
        var cabem = un ? todas.filter(function (o) { return o.dataset.unidade === un || o.dataset.unidade === 'ambos'; }) : [];
        cabem.forEach(function (o) { select.add(o); });
        var continua = cabem.some(function (o) { return o.value === atual; });
        select.value = continua ? atual : '';
        if (!continua && atual && !inicio) select.dispatchEvent(new Event('change'));
        return cabem.length;
      };
    }
    var ferramenta = document.getElementById('ss-ferramenta'), recurso = document.getElementById('ss-recurso');
    var itemTi = document.getElementById('ss-item'), itemInfo = document.getElementById('ss-item-info');
    var campos = { maquina: document.getElementById('ss-maquina-campo'), ferramenta: document.getElementById('ss-ferramenta-campo'), item_ti: document.getElementById('ss-item-campo') };
    var selects = { maquina: maquina, ferramenta: ferramenta, item_ti: itemTi };
    var ferramentaInfo = document.getElementById('ss-ferramenta-info');
    var filtrarAreas = filtro(area), filtrarMaquinas = filtro(maquina), filtrarFerramentas = filtro(ferramenta), filtrarItens = filtro(itemTi);

    // Máquina OU ferramenta/molde OU item de TI (cada um só nas unidades que aceitam): o campo que não vale fica escondido e vazio.
    function aplicarRecurso(inicio) {
      var un = unidade.value, nFerr = filtrarFerramentas(un, inicio), nItens = filtrarItens(un, inicio), disponiveis = 0;
      recurso.querySelectorAll('label').forEach(function (l) {
        var cabe = l.dataset.unidades === '*' || (un && l.dataset.unidades.split(',').indexOf(un) >= 0);
        l.hidden = !cabe;
        if (cabe) disponiveis++;
        else if (l.querySelector('input').checked) recurso.querySelector('input[value="maquina"]').checked = true;
      });
      recurso.hidden = disponiveis < 2;
      var escolhido = recurso.querySelector('input:checked').value;
      Object.keys(campos).forEach(function (k) {
        campos[k].hidden = k !== escolhido;
        if (k !== 'maquina') selects[k].required = k === escolhido;
        if (k !== escolhido && selects[k].value) { selects[k].value = ''; selects[k].dispatchEvent(new Event('change')); }
      });
      ferramentaInfo.textContent = escolhido === 'ferramenta' ? (nFerr ? nFerr + ' ferramenta(s)/molde(s) desta unidade.' : 'Nenhuma ferramenta/molde cadastrada nesta unidade.') : '';
      itemInfo.textContent = escolhido === 'item_ti' ? (nItens ? nItens + ' item(ns) do inventário desta unidade. Os que estão com você aparecem primeiro.' : 'Nenhum item do inventário nesta unidade.') : '';
    }
    recurso.querySelectorAll('input').forEach(function (r) { r.addEventListener('change', function () { aplicarRecurso(false); }); });
    // Item de TI com centro de custo: sugere o dele (pode trocar).
    itemTi.addEventListener('change', function () {
      var ccItem = itemTi.selectedOptions[0] ? itemTi.selectedOptions[0].dataset.cc : '';
      if (ccItem && !cc.disabled && temOpcao(ccItem)) { cc.value = ccItem; cc.dispatchEvent(new Event('change')); info.textContent = 'Centro de custo do cadastro do item.'; }
    });
    var semMaquina = maquina.options[0];

    function aplicarUnidade(inicio) {
      var un = unidade.value;
      var nAreas = filtrarAreas(un, inicio), nMaquinas = filtrarMaquinas(un, inicio);
      area.disabled = !un;
      area.options[0].textContent = un ? 'Escolha a área…' : 'Escolha primeiro a unidade';
      areaInfo.textContent = un && !nAreas ? 'Nenhuma área executante cadastrada nesta unidade.' : '';
      semMaquina.textContent = un ? 'Sem máquina (serviço geral)' : 'Escolha primeiro a unidade';
      maquinaInfo.textContent = un ? (nMaquinas ? nMaquinas + ' máquina(s) desta unidade.' : 'Nenhuma máquina cadastrada nesta unidade.') : '';
      if (!inicio && nAreas === 1) { area.value = area.options[1].value; }
    }
    unidade.addEventListener('change', function () { aplicarUnidade(false); aplicarRecurso(false); });
    maquina.addEventListener('change', function () { aplicarMaquina(false); });
    cc.addEventListener('change', function () { if (!cc.disabled && cc.value) info.textContent = cc.value === ccUsuario ? 'Centro de custo do seu cadastro.' : ''; });
    aplicarUnidade(true);
    aplicarRecurso(true);
    aplicarMaquina(true);

    // Os avisos ficam destacados quando marcados.
    document.querySelectorAll('.ss-alerta input').forEach(function (c) {
      var marcar = function () { c.closest('.ss-alerta').classList.toggle('marcado', c.checked); };
      c.addEventListener('change', marcar); marcar();
    });

    document.querySelectorAll('[data-sugestao]').forEach(function (b) {
      b.addEventListener('click', function () {
        var t = document.getElementById('ss-titulo');
        t.value = b.dataset.sugestao.slice(0, t.maxLength > 0 ? t.maxLength : undefined);
        t.dispatchEvent(new Event('input', { bubbles: true }));
        t.focus();
      });
    });
  })();
</script>
