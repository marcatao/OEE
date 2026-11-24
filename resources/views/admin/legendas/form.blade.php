@extends('layouts.portal')

@section('titulo', $legenda->exists ? 'Editar legenda' : 'Nova legenda')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Produção › <a href="{{ route('admin.legendas.index') }}">Legendas</a></p>
  <h1>{{ $legenda->exists ? $legenda->codigo.' — '.$legenda->descricao : 'Nova legenda' }}</h1>

  @if ($errors->any())<p class="aviso erro" role="alert">{{ $errors->first() }}</p>@endif

  <form method="post" class="formulario-vertical"
        action="{{ $legenda->exists ? route('admin.legendas.update', $legenda) : route('admin.legendas.store') }}">
    @csrf
    @if ($legenda->exists) @method('put') @endif

    <div class="campos">
        @include('partials.campo-unidade', ['registro' => $legenda])
      <label>Sigla
        <input name="codigo" required maxlength="12" value="{{ old('codigo', $legenda->codigo) }}" placeholder="Ex.: DT" style="text-transform:uppercase">
        <small>Aparece dentro da barra na timeline.</small>
      </label>
      <label>Descrição
        <input name="descricao" required maxlength="120" value="{{ old('descricao', $legenda->descricao) }}" placeholder="Ex.: Parada não planejada">
      </label>
      <label>Tipo
        <select name="tipo" required>
          @foreach (\App\Models\Legenda::TIPOS as $chave => $rotulo)
            <option value="{{ $chave }}" @selected(old('tipo', $legenda->tipo) === $chave)>{{ $rotulo }}</option>
          @endforeach
        </select>
        <small>Produzindo e paradas não planejadas entram na disponibilidade; planejadas e desligada ficam fora.</small>
      </label>
      <label>Cor na timeline
        <span style="display:flex;gap:.75rem;align-items:center">
          <input type="color" name="cor" required value="{{ old('cor', $legenda->cor) }}" aria-label="Cor da legenda">
          <span class="suave">Preenche a barra da máquina na Timeline.</span>
        </span>
      </label>
      <label>Ordem
        <input name="ordem" type="number" min="0" max="9999" value="{{ old('ordem', $legenda->ordem) }}">
        <small>Posição nas listas e no resumo.</small>
      </label>
    </div>

    <fieldset class="opcoes-linha">
      <legend>Exige OP</legend>
      <label class="opcao"><input type="radio" name="exige_op" value="1" @checked(old('exige_op', (int) $legenda->exige_op) == 1)> <span>Sim <small>o apontamento real com esta legenda precisa da ordem de produção</small></span></label>
      <label class="opcao"><input type="radio" name="exige_op" value="0" @checked(old('exige_op', (int) $legenda->exige_op) == 0)> <span>Não</span></label>
    </fieldset>

    <fieldset class="opcoes-linha">
      <legend>Exibir em dashboards</legend>
      <label class="opcao"><input type="radio" name="exibir_dashboards" value="1" @checked(old('exibir_dashboards', (int) ($legenda->exibir_dashboards ?? true)) == 1)> <span>Sim</span></label>
      <label class="opcao"><input type="radio" name="exibir_dashboards" value="0" @checked(old('exibir_dashboards', (int) ($legenda->exibir_dashboards ?? true)) == 0)> <span>Não <small>o tempo desta legenda não aparece nem entra nos cálculos dos dashboards de máquinas (disponibilidade, performance, tempo por legenda); a Timeline continua mostrando</small></span></label>
    </fieldset>

    <fieldset>
      <legend>Abre SS</legend>
      <p class="suave">Toda vez que esta legenda for apontada numa máquina (no portal, no aplicativo ou pelo IoT), uma solicitação de serviço
        é aberta sozinha para a área escolhida, com a máquina, o centro de custo dela e o IoT/canal como solicitante. Deixe em branco para não abrir.</p>
      @php($unSs = (string) old('abre_ss_unidade', $legenda->abre_ss_unidade))
      <div class="campos">
        <label>Unidade
          <select name="abre_ss_unidade" id="leg-ss-unidade">
            <option value="">Não abre SS</option>
            @foreach ($unidadesSs as $un)<option value="{{ $un }}" @selected($unSs === $un)>{{ config('oee.unidades')[$un] ?? $un }}</option>@endforeach
          </select>
        </label>
        <label>Área executante
          <select name="abre_ss_area_executante_id" id="leg-ss-area">
            <option value="">Escolha a área…</option>
            @foreach ($areasSs as $a)<option value="{{ $a->id }}" data-unidade="{{ $a->unidade }}" @selected((int) old('abre_ss_area_executante_id', $legenda->abre_ss_area_executante_id) === $a->id)>{{ $a->nome }}</option>@endforeach
          </select>
        </label>
      </div>
    </fieldset>
    <script>
      (function () {
        var un = document.getElementById('leg-ss-unidade'), area = document.getElementById('leg-ss-area');
        var todas = Array.prototype.slice.call(area.options).filter(function (o) { return o.value !== ''; });
        function aplicar() {
          var atual = area.value;
          todas.forEach(function (o) { if (o.parentNode) o.remove(); });
          todas.filter(function (o) { return un.value && (o.dataset.unidade === un.value || o.dataset.unidade === 'ambos'); }).forEach(function (o) { area.add(o); });
          area.value = Array.prototype.some.call(area.options, function (o) { return o.value === atual; }) ? atual : '';
          area.disabled = !un.value; area.required = !!un.value;
          area.options[0].textContent = un.value ? 'Escolha a área…' : '—';
        }
        un.addEventListener('change', aplicar); aplicar();
      })();
    </script>

    <fieldset class="opcoes-linha">
      <legend>Situação</legend>
      <label class="opcao"><input type="radio" name="ativo" value="1" @checked(old('ativo', (int) $legenda->ativo) == 1)> <span>Ativa</span></label>
      <label class="opcao"><input type="radio" name="ativo" value="0" @checked(old('ativo', (int) $legenda->ativo) == 0)> <span>Inativa <small>não aparece em apontamentos novos; o histórico continua com a cor</small></span></label>
    </fieldset>

    <div class="acoes">
      <button class="botao" type="submit">Salvar</button>
      <a class="botao secundario" href="{{ route('admin.legendas.index') }}">Cancelar</a>
    </div>
  </form>
</section>
@endsection
