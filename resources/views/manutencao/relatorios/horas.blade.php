@extends('layouts.portal')

@section('titulo', 'Relatório de horas da manutenção')

@use('App\Models\Manutencao\SolicitacaoServico')
@use('App\Services\Manutencao\HorasManutencao')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Ordens de serviço › Relatórios</p>
  <div class="titulo-com-acao">
    <h1>Apontamentos de horas</h1>
    <a class="botao secundario" href="{{ route('os.relatorios.horas', request()->query() + ['formato' => 'csv']) }}">Exportar para Excel (planilha geral)</a>
  </div>
  <p class="suave">Hora normal = dentro da jornada do mantenedor; o resto é hora extra. <strong>Décimo</strong> = total em horas decimais (1h30 = 1,50).</p>
  <form method="get" class="filtros">
    <label>De <input type="date" name="de" value="{{ $de->format('Y-m-d') }}"></label>
    <label>Até <input type="date" name="ate" value="{{ $ate->format('Y-m-d') }}"></label>
    @if (count($unidades) > 1)
      <label>Unidade <select name="unidade" id="rel-unidade"><option value="">Todas as minhas</option>@foreach ($unidades as $un)<option value="{{ $un }}" @selected($filtros['unidade'] === $un)>{{ config('oee.unidades')[$un] ?? $un }}</option>@endforeach</select></label>
    @endif
    <label>Área executante <select name="area" id="rel-area"><option value="">Todas</option>@foreach ($areas as $ar)<option value="{{ $ar->id }}" data-unidade="{{ $ar->unidade }}" @selected($filtros['area'] === $ar->id)>{{ $ar->nome }}</option>@endforeach</select></label>
    <label>Tipo <select name="tipo"><option value="">Todos</option>@foreach (SolicitacaoServico::TIPOS as $v => $r)<option value="{{ $v }}" @selected($filtros['tipo'] === $v)>{{ $r }}</option>@endforeach</select></label>
    <label>Mantenedor <select name="mantenedor" data-busca><option value="">Todos</option>@foreach ($mantenedores as $m)<option value="{{ $m->id }}" @selected($filtros['mantenedor'] === $m->id)>{{ $m->nome }}</option>@endforeach</select></label>
    <label>Máquina <select name="maquina" data-busca><option value="">Todas</option>@foreach ($maquinas as $m)<option value="{{ $m->id }}" @selected($filtros['maquina'] === $m->id)>{{ $m->codigo }} — {{ $m->nome }}</option>@endforeach</select></label>
    <label>Centro de custo <select name="centro_custo" data-busca><option value="">Todos</option>@foreach ($centros as $c)<option value="{{ $c->codigo() }}" @selected($filtros['centro_custo'] === $c->codigo())>{{ $c->codigo() }} — {{ $c->descricao() }}</option>@endforeach</select></label>
    <button class="botao secundario" type="submit">Filtrar</button>
  </form>
  <script>
    // Com uma unidade escolhida, a lista de áreas executantes mostra só as dela (e as de "Todas").
    (function () {
      var un = document.getElementById('rel-unidade'), area = document.getElementById('rel-area');
      if (!un || !area) return;
      var todas = Array.prototype.slice.call(area.options).filter(function (o) { return o.value !== ''; });
      function aplicar() {
        var atual = area.value;
        todas.forEach(function (o) { if (o.parentNode) o.remove(); });
        todas.filter(function (o) { return !un.value || o.dataset.unidade === un.value || o.dataset.unidade === 'ambos'; }).forEach(function (o) { area.add(o); });
        area.value = Array.prototype.some.call(area.options, function (o) { return o.value === atual; }) ? atual : '';
      }
      un.addEventListener('change', aplicar); aplicar();
    })();
  </script>

  <div class="kpis">
    <div class="kpi"><div><span>Hora normal</span><strong>{{ HorasManutencao::hhmm($totalNormais) }}</strong></div></div>
    <div class="kpi"><div><span>Hora extra</span><strong>{{ HorasManutencao::hhmm($totalExtras) }}</strong></div></div>
    <div class="kpi"><div><span>Total (décimo)</span><strong>{{ HorasManutencao::decimal($totalNormais + $totalExtras) }} h</strong></div></div>
    <div class="kpi"><div><span>Apontamentos</span><strong>{{ $linhas->count() }}</strong></div></div>
  </div>

  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Cód. OS</th><th>Data</th><th>Área executante</th><th>Máquina</th><th>Tipo OS</th><th>Hora inicial</th><th>Hora final</th><th class="num">Hora normal</th><th class="num">Hora extra</th><th class="num">Décimo</th>
      <th>Funcionário</th><th>Lançado por</th><th>Centro de custo</th><th>Descrição</th></tr></thead>
    <tbody>
    @forelse ($linhas as $a)
      @php($os = $a->ordemServico)
      <tr>
        <td><a href="{{ route('os.ordens.show', $os) }}">{{ $os->numeroFormatado() }}</a></td>
        <td>{{ $a->inicio->setTimezone(config('oee.fuso_exibicao'))->format('d/m/Y') }}</td>
        <td>{{ $os->areaExecutante?->nome ?? '—' }}@if (count($unidades) > 1)<br><small class="suave">{{ config('oee.unidades')[$os->unidade] ?? $os->unidade }}</small>@endif</td>
        <td>@include('manutencao._maquina', ['maquina' => $os->maquina])</td>
        <td>{{ $os->rotuloTipo() }}</td>
        <td>{{ $a->inicio->setTimezone(config('oee.fuso_exibicao'))->format('H:i') }}</td>
        <td>{{ $a->fim->setTimezone(config('oee.fuso_exibicao'))->format('H:i') }}</td>
        <td class="num">{{ HorasManutencao::hhmm($a->minutos_normais) }}</td>
        <td class="num">{{ HorasManutencao::hhmm($a->minutos_extras) }}</td>
        <td class="num">{{ HorasManutencao::decimal((int) $a->minutos_normais + (int) $a->minutos_extras) }}</td>
        <td>{{ $a->mantenedor?->nome }}</td>
        <td>{{ $a->criadoPor?->nome }}</td>
        <td>{{ $os->centro_custo }}</td>
        <td>{{ $a->descricao }}</td>
      </tr>
    @empty
      <tr><td colspan="14" class="suave">Nenhum apontamento no período.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
</section>

<section class="cartao">
  <h2>Total apontado × total disponível <small class="suave">com os filtros acima</small></h2>
  <p class="suave">Disponível = jornada − intervalo nos dias de trabalho do período (até hoje). Meta: acima de 90%.</p>
  @include('manutencao._aproveitamento', ['aproveitamento' => $aproveitamento])
</section>
@endsection
