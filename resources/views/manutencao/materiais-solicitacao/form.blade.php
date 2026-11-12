@extends('layouts.portal')

@section('titulo', 'Nova solicitação de material')

@section('conteudo')
@php($itensAntigos = old('itens', [[]]))
<section class="cartao">
  <p class="trilha">Ordens de serviço › <a href="{{ route('os.materiais-solicitacao.index') }}">Solicitações de material</a></p>
  <div class="titulo-com-acao">
    <h1>Nova solicitação de material</h1>
    <span class="ss-autor">@include('partials.avatar', ['usuario' => auth()->user()])
      <span><small class="suave">Solicitante</small><br><strong>{{ auth()->user()->nome }}</strong></span></span>
  </div>
  <p class="suave">Peça aqui o material que precisa. A administração da área analisa cada item e decide se vai para o almoxarifado
    ou para compras. A data e a hora do pedido ficam gravadas.</p>
  @if ($errors->any())
    <div class="aviso erro" role="alert">@foreach (array_unique($errors->all()) as $erro)<div>{{ $erro }}</div>@endforeach</div>
  @endif

  <form method="post" action="{{ route('os.materiais-solicitacao.store') }}" class="formulario-vertical" id="sm-form">
    @csrf
    @if (count($unidades) > 1)
      <div class="campos">
        <label>Área (quem aprova)
          <select name="unidade">@foreach ($unidades as $un)<option value="{{ $un }}" @selected(old('unidade') === $un)>{{ config('oee.unidades')[$un] ?? $un }}</option>@endforeach</select>
        </label>
      </div>
    @else
      <input type="hidden" name="unidade" value="{{ $unidades[0] }}">
    @endif

    <fieldset>
      <legend>Itens</legend>
      <div class="sm-itens" id="sm-itens">
        @foreach ($itensAntigos as $i => $item)
          @include('manutencao.materiais-solicitacao._linha', ['i' => $i, 'item' => $item])
        @endforeach
      </div>
      <button type="button" class="botao secundario" id="sm-adicionar">@include('layouts.partials.icone', ['nome' => 'mais']) Adicionar item</button>
    </fieldset>

    <label>Observação geral <small class="suave">(opcional)</small>
      <textarea name="observacao" rows="2" maxlength="1000" placeholder="Algo que vale para a solicitação toda">{{ old('observacao') }}</textarea></label>

    <div class="acoes">
      <button class="botao" type="submit">Enviar solicitação</button>
      <a class="botao secundario" href="{{ route('os.materiais-solicitacao.index') }}">Cancelar</a>
    </div>
  </form>
  <template id="sm-modelo">@include('manutencao.materiais-solicitacao._linha', ['i' => '__I__', 'item' => []])</template>
</section>
<script>
  (function () {
    var lista = document.getElementById('sm-itens'), modelo = document.getElementById('sm-modelo'), proximo = {{ count($itensAntigos) }};
    function renumerar() {
      var linhas = lista.querySelectorAll('[data-sm-item]');
      linhas.forEach(function (l, n) {
        l.querySelector('[data-sm-numero]').textContent = n + 1;
        l.querySelector('[data-sm-remover]').hidden = linhas.length === 1;
      });
    }
    document.getElementById('sm-adicionar').addEventListener('click', function () {
      var html = modelo.innerHTML.replace(/__I__/g, String(proximo++));
      var caixa = document.createElement('div'); caixa.innerHTML = html.trim();
      var linha = caixa.firstElementChild;
      lista.appendChild(linha);
      window.OeeComponentes.iniciar(linha);
      renumerar();
      var botao = linha.querySelector('.sm-produto .busca-select-botao'); if (botao) botao.click();
    });
    lista.addEventListener('click', function (e) {
      var b = e.target.closest('[data-sm-remover]');
      if (b) { b.closest('[data-sm-item]').remove(); renumerar(); }
    });
    renumerar();
  })();
</script>
@endsection
