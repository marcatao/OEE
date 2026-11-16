{{-- Campos da SS (abertura, edição e revisão do aprovador no detalhe). Variáveis: $ss, $maquinas, $areas, $centros,
     $ccUsuario, $sugestoes. Precisa estar dentro de um <form>. --}}
@use('App\Models\Manutencao\SolicitacaoServico')
    <input type="hidden" name="apontamento_estado_id" value="{{ old('apontamento_estado_id', $ss->apontamento_estado_id) }}">
    <input type="hidden" name="ordem_producao_id" value="{{ old('ordem_producao_id', $ss->ordem_producao_id) }}">

    <fieldset>
      <legend>O que precisa</legend>
      <div class="campos ss-campos">
        <label class="largo">Título resumido
          <input name="titulo" required maxlength="120" value="{{ old('titulo', $ss->titulo) }}" placeholder="Ex.: Vazamento de óleo no cilindro da injetora" autofocus>
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
        <label>Área executante
          <select name="area_executante_id" id="ss-area" required>
            <option value="">Escolha…</option>
            @foreach ($areas as $a)
              <option value="{{ $a->id }}" data-unidade="{{ $a->unidade }}" @selected((int) old('area_executante_id', $ss->area_executante_id) === $a->id)>{{ $a->nome }} — {{ config('oee.unidades')[$a->unidade] ?? $a->unidade }}</option>
            @endforeach
          </select>
        </label>
        <label class="opcao ss-parada"><input type="checkbox" name="maquina_parada" value="1" @checked(old('maquina_parada', $ss->maquina_parada))>
          <span><strong>Máquina parada?</strong><small>marque se o problema parou a máquina</small></span></label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Onde</legend>
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

    <fieldset>
      <legend>Descrição do problema ou do serviço</legend>
      <textarea name="descricao" rows="6" maxlength="2000" required id="ss-descricao" placeholder="Conte o que está acontecendo, desde quando, o que já foi feito…">{{ old('descricao', $ss->descricao) }}</textarea>
      @if ($sugestoes->isNotEmpty())
        <div class="ss-sugestoes"><small class="suave">Sugestões:</small>
          @foreach ($sugestoes as $s)<button type="button" class="ss-chip" data-sugestao="{{ $s }}">{{ $s }}</button>@endforeach
        </div>
      @endif
    </fieldset>
<script>
  (function () {
    var ccUsuario = @json((string) $ccUsuario);
    var maquina = document.getElementById('ss-maquina'), cc = document.getElementById('ss-cc'), info = document.getElementById('ss-cc-info');
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
    maquina.addEventListener('change', function () { aplicarMaquina(false); });

    // Máquinas só da área executante escolhida (as da mesma unidade e as cadastradas para "Todas"); sem área, nenhuma.
    var area = document.getElementById('ss-area'), maquinaInfo = document.getElementById('ss-maquina-info');
    var todasMaquinas = Array.prototype.slice.call(maquina.options).filter(function (o) { return o.value !== ''; });
    var semMaquina = maquina.options[0];
    function filtrarMaquinas(inicio) {
      var op = area.selectedOptions[0], unidade = op && op.value ? op.dataset.unidade : null, atual = maquina.value;
      todasMaquinas.forEach(function (o) { if (o.parentNode) o.remove(); });
      var cabem = unidade ? todasMaquinas.filter(function (o) { return unidade === 'ambos' || o.dataset.unidade === unidade || o.dataset.unidade === 'ambos'; }) : [];
      cabem.forEach(function (o) { maquina.add(o); });
      semMaquina.textContent = unidade ? 'Sem máquina (serviço geral)' : 'Escolha primeiro a área executante';
      maquinaInfo.textContent = unidade ? (cabem.length ? cabem.length + ' máquina(s) da área.' : 'Nenhuma máquina cadastrada nesta área.') : '';
      var continua = cabem.some(function (o) { return o.value === atual; });
      maquina.value = continua ? atual : '';
      if (!continua && atual && !inicio) maquina.dispatchEvent(new Event('change'));
    }
    area.addEventListener('change', function () { filtrarMaquinas(false); });
    filtrarMaquinas(true);
    cc.addEventListener('change', function () { if (!cc.disabled && cc.value) info.textContent = cc.value === ccUsuario ? 'Centro de custo do seu cadastro.' : ''; });
    aplicarMaquina(true);

    document.querySelectorAll('[data-sugestao]').forEach(function (b) {
      b.addEventListener('click', function () {
        var d = document.getElementById('ss-descricao');
        d.value = d.value ? d.value.replace(/\s*$/, '') + '\n' + b.dataset.sugestao : b.dataset.sugestao;
        d.focus();
      });
    });
  })();
</script>
