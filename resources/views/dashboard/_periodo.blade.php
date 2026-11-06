{{-- Seletor de período dos painéis. Parâmetros: periodo e acao (URL do formulário). --}}
<form method="get" action="{{ $acao }}" class="filtros">
  <label>Período
    <select name="periodo" onchange="this.form.submit()">
      @foreach (\App\Services\Periodo::OPCOES as $chave => $rotulo)
        <option value="{{ $chave }}" @selected($periodo->chave === $chave)>{{ $rotulo }}</option>
      @endforeach
    </select>
  </label>
  <noscript><button class="botao secundario" type="submit">Aplicar</button></noscript>
  <a class="botao secundario" href="{{ $acao }}?periodo={{ $periodo->chave }}" title="Atualizar os números">@include('layouts.partials.icone', ['nome' => 'atualizar']) Atualizar</a>
</form>
