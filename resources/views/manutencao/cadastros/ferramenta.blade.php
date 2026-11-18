@extends('layouts.portal')

@section('titulo', $f->exists ? 'Equipamento '.$f->codigo : 'Novo equipamento')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Ordens de serviço › <a href="{{ route('os.cadastros.ferramentas') }}">Equipamentos</a></p>
  <h1>{{ $f->exists ? $f->codigo.' — '.$f->descricao : 'Novo equipamento' }}</h1>
  @include('manutencao._erros')
  <form method="post" class="formulario-vertical" action="{{ $f->exists ? route('os.cadastros.ferramentas.update', $f) : route('os.cadastros.ferramentas.store') }}">
    @csrf
    @if ($f->exists) @method('put') @endif
    <div class="campos">
      @include('partials.campo-unidade', ['registro' => $f])
      <label>Código / patrimônio <input name="codigo" required maxlength="30" value="{{ old('codigo', $f->codigo) }}" style="text-transform:uppercase"></label>
      <label class="largo">Descrição <input name="descricao" required maxlength="150" value="{{ old('descricao', $f->descricao) }}" placeholder="Ex.: Multímetro digital, notebook de campo"></label>
      <label>Marca / modelo <input name="marca_modelo" maxlength="100" value="{{ old('marca_modelo', $f->marca_modelo) }}"></label>
      <label>Nº de série <input name="numero_serie" maxlength="60" value="{{ old('numero_serie', $f->numero_serie) }}"></label>
      <label>Está com
        <select name="mantenedor_id" data-busca="Buscar mantenedor…">
          <option value="">Avulso (sem mantenedor)</option>
          @foreach ($mantenedores as $m)<option value="{{ $m->id }}" @selected((int) old('mantenedor_id', $f->mantenedor_id) === $m->id)>{{ $m->nomeExibicao() }}</option>@endforeach
        </select>
        @if ($f->atribuida_em)<small>Desde @dataHora($f->atribuida_em).</small>@endif
      </label>
      <label class="largo">Observação <input name="observacao" maxlength="500" value="{{ old('observacao', $f->observacao) }}"></label>
    </div>
    {{-- Atributos livres: quantos quiser, cada um com nome e valor (ex.: IP = 10.0.5.20). --}}
    @php
      $atributos = collect(old('atributos', $f->atributos->map(fn ($a) => ['nome' => $a->nome, 'valor' => $a->valor])->all()))->values();
    @endphp
    <fieldset class="cartao" data-atributos>
      <legend>Atributos</legend>
      <p class="suave">Informações extras do equipamento, com nome e valor. Ex.: <strong>IP</strong> = 10.0.5.20, <strong>Versão</strong> = 2.1,
        <strong>Calibração</strong> = 03/2027. Cadastre quantos precisar.</p>
      <table class="tabela tabela-form">
        <thead><tr><th>Atributo</th><th>Valor</th><th></th></tr></thead>
        <tbody data-atributos-linhas>
          @foreach ($atributos as $i => $a)
            <tr>
              <td><input name="atributos[{{ $i }}][nome]" maxlength="60" list="nomes-atributos" value="{{ $a['nome'] ?? '' }}" aria-label="Atributo" placeholder="Ex.: IP"></td>
              <td><input name="atributos[{{ $i }}][valor]" maxlength="255" value="{{ $a['valor'] ?? '' }}" aria-label="Valor" placeholder="Ex.: 10.0.5.20"></td>
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
      <datalist id="nomes-atributos">@foreach ($nomesAtributos as $nome)<option value="{{ $nome }}">@endforeach</datalist>
      <button type="button" class="botao secundario pequeno" data-adicionar-atributo>@include('layouts.partials.icone', ['nome' => 'mais']) Adicionar atributo</button>
    </fieldset>
    <script>
      (function () {
        const caixa = document.querySelector('[data-atributos]');
        const linhas = caixa.querySelector('[data-atributos-linhas]');
        let proximo = {{ $atributos->count() }};
        caixa.querySelector('[data-adicionar-atributo]').addEventListener('click', function () {
          const html = caixa.querySelector('[data-modelo-atributo]').innerHTML.replaceAll('__N__', String(proximo++));
          linhas.insertAdjacentHTML('beforeend', html);
          linhas.lastElementChild.querySelector('input').focus();
        });
        linhas.addEventListener('click', function (e) {
          const botao = e.target.closest('[data-remover-atributo]');
          if (botao) botao.closest('tr').remove();
        });
        if (proximo === 0) caixa.querySelector('[data-adicionar-atributo]').click();
      })();
    </script>

    <fieldset class="opcoes-linha">
      <legend>Situação</legend>
      <label class="opcao"><input type="radio" name="ativo" value="1" @checked(old('ativo', (int) $f->ativo) == 1)> <span>Ativo</span></label>
      <label class="opcao"><input type="radio" name="ativo" value="0" @checked(old('ativo', (int) $f->ativo) == 0)> <span>Inativo <small>baixado, perdido ou descartado</small></span></label>
    </fieldset>
    <div class="acoes">
      <button class="botao" type="submit">Salvar</button>
      <a class="botao secundario" href="{{ route('os.cadastros.ferramentas') }}">Cancelar</a>
    </div>
  </form>
</section>
@endsection
