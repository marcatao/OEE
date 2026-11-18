{{-- Campos da SS (abertura, edição e revisão do aprovador no detalhe), em etapas: para quem → onde → o que → detalhes.
     Variáveis: $ss, $unidadesSs, $maquinas, $areas, $centros, $ccUsuario, $sugestoes. Precisa estar dentro de um <form>. --}}
@use('App\Models\Manutencao\SolicitacaoServico')
@php($unidadeAtual = (string) old('unidade', $ss->unidade ?: (count($unidadesSs) === 1 ? $unidadesSs[0] : '')))
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
      <p class="suave ss-ajuda">Com máquina, o centro de custo vem do cadastro dela. Sem máquina, é um serviço geral: informe o centro de custo.</p>
      <div class="campos ss-campos alinhar-topo">
        <label class="largo-2">Máquina / equipamento
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
          <input name="titulo" required maxlength="120" value="{{ old('titulo', $ss->titulo) }}" placeholder="Ex.: Vazamento de óleo no cilindro da injetora">
        </label>
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
      @if ($sugestoes->isNotEmpty())
        <div class="ss-sugestoes"><small class="suave">Sugestões (clique para usar):</small>
          @foreach ($sugestoes as $s)<button type="button" class="ss-chip" data-sugestao="{{ $s }}">{{ $s }}</button>@endforeach
        </div>
      @endif
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
    var filtrarAreas = filtro(area), filtrarMaquinas = filtro(maquina);
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
    unidade.addEventListener('change', function () { aplicarUnidade(false); });
    maquina.addEventListener('change', function () { aplicarMaquina(false); });
    cc.addEventListener('change', function () { if (!cc.disabled && cc.value) info.textContent = cc.value === ccUsuario ? 'Centro de custo do seu cadastro.' : ''; });
    aplicarUnidade(true);
    aplicarMaquina(true);

    // Os avisos ficam destacados quando marcados.
    document.querySelectorAll('.ss-alerta input').forEach(function (c) {
      var marcar = function () { c.closest('.ss-alerta').classList.toggle('marcado', c.checked); };
      c.addEventListener('change', marcar); marcar();
    });

    document.querySelectorAll('[data-sugestao]').forEach(function (b) {
      b.addEventListener('click', function () {
        var d = document.getElementById('ss-descricao');
        d.value = d.value ? d.value.replace(/\s*$/, '') + '\n' + b.dataset.sugestao : b.dataset.sugestao;
        d.focus();
      });
    });
  })();
</script>
