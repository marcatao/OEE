@extends('layouts.portal')

@section('titulo', $i->exists ? 'Item '.$i->codigo() : 'Novo item do inventário')

@use('App\Models\InventarioTi\ItemTi')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Tecnologia › <a href="{{ route('inventario-ti.index') }}">Inventário de TI</a>@if ($i->exists) › <a href="{{ route('inventario-ti.show', $i) }}">{{ $i->codigo() }}</a>@endif</p>
  <h1>{{ $i->exists ? $i->identificacao() : 'Novo item do inventário' }}</h1>
  @include('manutencao._erros')
  <form method="post" class="formulario-vertical" action="{{ $i->exists ? route('inventario-ti.update', $i) : route('inventario-ti.store') }}">
    @csrf
    @if ($i->exists) @method('put') @endif

    <fieldset class="cartao">
      <legend>Identificação</legend>
      <div class="campos">
        @include('partials.campo-unidade', ['registro' => $i])
        <label>Tipo
          <select name="tipo_id" id="ti-tipo" required>
            <option value="">Escolha o tipo…</option>
            @foreach ($tipos->groupBy('categoria') as $categoria => $doGrupo)
              <optgroup label="{{ ItemTi::CATEGORIAS[$categoria] ?? $categoria }}">
                @foreach ($doGrupo as $t)<option value="{{ $t->id }}" data-categoria="{{ $t->categoria }}" @selected((int) old('tipo_id', $i->tipo_id) === $t->id)>{{ $t->nome }}</option>@endforeach
              </optgroup>
            @endforeach
          </select>
          @if ($i->exists)<small class="suave">A categoria ({{ $i->rotuloCategoria() }}) não muda.</small>@endif
        </label>
        <label>Patrimônio <input name="patrimonio" maxlength="30" value="{{ old('patrimonio', $i->patrimonio) }}" style="text-transform:uppercase" placeholder="Plaqueta ou código interno"></label>
        @php($descricaoAntiga = $i->exists && ! $i->descricao_id && filled($i->descricao))
        <label class="largo">Descrição
          <select name="descricao_id" id="ti-descricao" data-busca="Buscar descrição…" @required(! $descricaoAntiga)>
            <option value="">{{ $descricaoAntiga ? 'Manter a atual: '.$i->descricao : 'Escolha a descrição…' }}</option>
            @foreach ($descricoes as $d)<option value="{{ $d->id }}" data-tipo="{{ $d->tipo_id }}" @selected((int) old('descricao_id', $i->descricao_id) === $d->id)>{{ $d->descricao }}</option>@endforeach
          </select>
          <small class="suave">Só as descrições padrão do tipo escolhido (ou de qualquer tipo). Falta uma? <a href="{{ route('inventario-ti.descricoes') }}" target="_blank" rel="noopener">Cadastre em Descrições</a>.</small>
        </label>
        <label>Marca <input name="marca" maxlength="60" value="{{ old('marca', $i->marca) }}"></label>
        <label>Modelo <input name="modelo" maxlength="80" value="{{ old('modelo', $i->modelo) }}"></label>
        <label><span data-rotulo-serie>Nº de série</span> <input name="numero_serie" maxlength="80" value="{{ old('numero_serie', $i->numero_serie) }}"></label>
      </div>
    </fieldset>

    <fieldset class="cartao ti-campos-categoria" data-categoria-campos="hardware">
      <legend>Equipamento</legend>
      <div class="campos">
        <label>Nome na rede (hostname) <input name="hostname" maxlength="60" value="{{ old('hostname', $i->hostname) }}"></label>
        <label class="largo">Acessórios <input name="acessorios" maxlength="255" value="{{ old('acessorios', $i->acessorios) }}" placeholder="Ex.: carregador, mouse, mochila (vão no termo)"></label>
        <label class="largo">Especificação <textarea name="especificacao" rows="3" maxlength="2000" placeholder="Processador, memória, disco, sistema operacional…">{{ old('especificacao', $i->especificacao) }}</textarea></label>
      </div>
    </fieldset>

    <fieldset class="cartao ti-campos-categoria" data-categoria-campos="licenca">
      <legend>Licença de software</legend>
      <div class="campos">
        <label class="largo">Chave, contrato ou conta <input name="licenca_chave" maxlength="255" value="{{ old('licenca_chave', $i->licenca_chave) }}" autocomplete="off"></label>
        <label>Quantidade de licenças <input name="quantidade_licencas" type="number" min="1" step="1" value="{{ old('quantidade_licencas', $i->quantidade_licencas) }}" placeholder="Em branco = sem limite"></label>
      </div>
    </fieldset>

    <fieldset class="cartao ti-campos-categoria" data-categoria-campos="linha">
      <legend>Linha / chip</legend>
      <div class="campos">
        <label>Número da linha <input name="linha_numero" maxlength="20" value="{{ old('linha_numero', $i->linha_numero) }}" placeholder="(11) 99999-0000"></label>
        <label>Operadora <input name="operadora" maxlength="40" value="{{ old('operadora', $i->operadora) }}"></label>
        <label>Plano <input name="plano" maxlength="80" value="{{ old('plano', $i->plano) }}"></label>
        <label class="largo">Aparelho onde está o chip
          <select name="vinculado_item_id" data-busca="Buscar aparelho por patrimônio ou descrição…">
            <option value="">Nenhum (chip guardado)</option>
            @foreach ($equipamentos as $e)<option value="{{ $e->id }}" @selected((int) old('vinculado_item_id', $i->vinculado_item_id) === $e->id)>{{ $e->identificacao() }}</option>@endforeach
          </select></label>
      </div>
    </fieldset>

    {{-- Atributos livres: quantos quiser, cada um com nome e valor (ex.: IP = 10.0.5.20). --}}
    @php($atributos = collect(old('atributos', $i->exists ? $i->atributos->map(fn ($a) => ['nome' => $a->nome, 'valor' => $a->valor])->all() : []))->values())
    <fieldset class="cartao" data-atributos>
      <legend>Atributos</legend>
      <p class="suave">Informações extras do item, com nome e valor. Ex.: <strong>IP</strong> = 10.0.5.20, <strong>Memória</strong> = 16 GB,
        <strong>Office</strong> = 2021, <strong>IMEI</strong> = 3544… Cadastre quantos precisar; a busca da lista também procura nos atributos.</p>
      <table class="tabela tabela-form">
        <thead><tr><th>Atributo</th><th>Valor</th><th></th></tr></thead>
        <tbody data-atributos-linhas>
          @foreach ($atributos as $n => $a)
            <tr>
              <td><input name="atributos[{{ $n }}][nome]" maxlength="60" list="nomes-atributos" value="{{ $a['nome'] ?? '' }}" aria-label="Atributo" placeholder="Ex.: IP"></td>
              <td><input name="atributos[{{ $n }}][valor]" maxlength="255" value="{{ $a['valor'] ?? '' }}" aria-label="Valor" placeholder="Ex.: 10.0.5.20"></td>
              <td><button type="button" class="botao secundario pequeno" data-remover-atributo>Remover</button></td>
            </tr>
          @endforeach
        </tbody>
      </table>
      <template data-modelo-atributo>
        <tr>
          <td><input name="atributos[__N__][nome]" maxlength="60" list="nomes-atributos" aria-label="Atributo" placeholder="Ex.: IP"></td>
          <td><input name="atributos[__N__][valor]" maxlength="255" aria-label="Valor" placeholder="Ex.: 10.0.5.20"></td>
          <td><button type="button" class="botao secundario pequeno" data-remover-atributo>Remover</button></td>
        </tr>
      </template>
      <datalist id="nomes-atributos">@foreach ($nomesAtributos as $nomeAtributo)<option value="{{ $nomeAtributo }}">@endforeach</datalist>
      <button type="button" class="botao secundario pequeno" data-adicionar-atributo>@include('layouts.partials.icone', ['nome' => 'mais']) Adicionar atributo</button>
    </fieldset>

    <fieldset class="cartao">
      <legend>Com quem e onde está</legend>
      <div class="campos">
        <label class="largo">Responsável
          <select name="responsavel_id" data-busca="Buscar usuário do portal por nome…">
            <option value="">Ninguém</option>
            @foreach ($usuarios as $usu)<option value="{{ $usu->id }}" @selected((int) old('responsavel_id', $i->responsavel_id) === $usu->id)>{{ $usu->nome }} — {{ $usu->email }}</option>@endforeach
          </select>
          <small class="suave">Trocar o responsável de um equipamento ou linha gera um termo de responsabilidade para ele assinar pelo portal.</small>
        </label>
        <label>Local / setor <input name="local" maxlength="100" list="ti-locais" value="{{ old('local', $i->local) }}" placeholder="Ex.: Expedição, Sala de reunião 2"></label>
        <datalist id="ti-locais">@foreach ($locais as $l)<option value="{{ $l }}">@endforeach</datalist>
        <label class="largo">Centro de custo
          <select name="centro_custo" data-busca="Buscar centro de custo por código ou nome…">
            <option value="">Nenhum</option>
            @foreach ($centros as $c)<option value="{{ $c->codigo() }}" @selected(old('centro_custo', $i->centro_custo) === $c->codigo())>{{ $c->codigo() }} — {{ $c->descricao() }}</option>@endforeach
          </select></label>
        <label>Situação
          <select name="situacao" id="ti-situacao" required>
            @foreach (ItemTi::SITUACOES as $valor => $rotulo)<option value="{{ $valor }}" @selected(old('situacao', $i->situacao) === $valor)>{{ $rotulo }}</option>@endforeach
          </select></label>
        <label class="largo" id="ti-motivo-baixa">Motivo da baixa <input name="motivo_baixa" maxlength="255" value="{{ old('motivo_baixa', $i->motivo_baixa) }}" placeholder="Ex.: descarte, furto, vendido, sem conserto"></label>
        <label class="largo">Observação da movimentação <input name="observacao_movimentacao" maxlength="500" placeholder="Opcional: fica no histórico junto com o que mudou"></label>
      </div>
    </fieldset>

    <fieldset class="cartao">
      <legend>Compra e garantia</legend>
      <div class="campos">
        <label>Data da compra <input type="date" name="data_compra" value="{{ old('data_compra', $i->data_compra?->format('Y-m-d')) }}"></label>
        <label>Nota fiscal <input name="nota_fiscal" maxlength="30" value="{{ old('nota_fiscal', $i->nota_fiscal) }}"></label>
        <label>Fornecedor <input name="fornecedor" maxlength="100" value="{{ old('fornecedor', $i->fornecedor) }}"></label>
        <label>Valor (R$) <input name="valor" type="number" min="0" step="0.01" value="{{ old('valor', $i->valor) }}"></label>
        <label>Garantia até <input type="date" name="garantia_ate" value="{{ old('garantia_ate', $i->garantia_ate?->format('Y-m-d')) }}"></label>
        <label data-vencimento>Vencimento <input type="date" name="vencimento" value="{{ old('vencimento', $i->vencimento?->format('Y-m-d')) }}"><small class="suave">Da assinatura da licença ou do plano da linha.</small></label>
        <label class="largo">Observação <input name="observacao" maxlength="500" value="{{ old('observacao', $i->observacao) }}"></label>
      </div>
    </fieldset>

    <div class="acoes">
      <button class="botao" type="submit">Salvar</button>
      <a class="botao secundario" href="{{ $i->exists ? route('inventario-ti.show', $i) : route('inventario-ti.index') }}">Cancelar</a>
    </div>
  </form>
</section>
<script>
  (function () {
    // Mostra só os campos da categoria do tipo escolhido (o servidor também limpa os que não valem).
    var tipo = document.getElementById('ti-tipo'), situacao = document.getElementById('ti-situacao');
    var serie = document.querySelector('[data-rotulo-serie]'), vencimento = document.querySelector('[data-vencimento]');
    // Descrições: só as do tipo escolhido e as de qualquer tipo (guarda as opções para poder voltar).
    var descricao = document.getElementById('ti-descricao');
    var todasDescricoes = Array.prototype.slice.call(descricao.options).filter(function (o) { return o.value !== ''; });
    function filtrarDescricoes() {
      var atual = descricao.value;
      todasDescricoes.forEach(function (o) { if (o.parentNode) o.remove(); });
      todasDescricoes.filter(function (o) { return !o.dataset.tipo || o.dataset.tipo === tipo.value; }).forEach(function (o) { descricao.add(o); });
      var continua = Array.prototype.some.call(descricao.options, function (o) { return o.value === atual; });
      descricao.value = continua ? atual : '';
      descricao.dispatchEvent(new Event('change'));
    }
    function aplicar() {
      filtrarDescricoes();
      var cat = tipo.selectedOptions[0] ? tipo.selectedOptions[0].dataset.categoria || '' : '';
      document.querySelectorAll('[data-categoria-campos]').forEach(function (f) { f.hidden = f.dataset.categoriaCampos !== cat; });
      serie.textContent = cat === 'linha' ? 'ICCID do chip' : 'Nº de série';
      vencimento.hidden = cat === 'hardware';
    }
    function aplicarSituacao() {
      var baixa = document.getElementById('ti-motivo-baixa');
      baixa.hidden = situacao.value !== 'baixado';
      baixa.querySelector('input').required = situacao.value === 'baixado';
    }
    tipo.addEventListener('change', aplicar); situacao.addEventListener('change', aplicarSituacao);
    aplicar(); aplicarSituacao();

    // Atributos: acrescentar e remover linhas.
    var caixa = document.querySelector('[data-atributos]'), linhas = caixa.querySelector('[data-atributos-linhas]');
    var proximo = {{ $atributos->count() }};
    caixa.querySelector('[data-adicionar-atributo]').addEventListener('click', function () {
      linhas.insertAdjacentHTML('beforeend', caixa.querySelector('[data-modelo-atributo]').innerHTML.replaceAll('__N__', String(proximo++)));
      linhas.lastElementChild.querySelector('input').focus();
    });
    linhas.addEventListener('click', function (e) {
      var botao = e.target.closest('[data-remover-atributo]');
      if (botao) botao.closest('tr').remove();
    });
  })();
</script>
@endsection
