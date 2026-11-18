{{--
  Filtro de unidade das listas de cadastro (Mantenedores, Áreas executantes): só as unidades habilitadas no login
  (AcessoOs::filtroUnidade). Aparece só para quem tem mais de uma. Variáveis: $unidades (lista), $unidade (escolhida) e,
  opcional, $manter (outros parâmetros da tela que o filtro mantém, ex.: ['ano' => 2026]).
--}}
@if (count($unidades) > 1)
  <form method="get" class="filtros">
    @foreach ($manter ?? [] as $campo => $valor)<input type="hidden" name="{{ $campo }}" value="{{ $valor }}">@endforeach
    <label>Unidade
      <select name="unidade" onchange="this.form.submit()">
        <option value="">Todas as minhas</option>
        @foreach ($unidades as $un)<option value="{{ $un }}" @selected($unidade === $un)>{{ config('oee.unidades')[$un] ?? $un }}</option>@endforeach
      </select></label>
    <noscript><button class="botao pequeno" type="submit">Filtrar</button></noscript>
  </form>
@endif
