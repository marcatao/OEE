{{-- Apontado × disponível por mantenedor (meta > 90%). Variável: $aproveitamento --}}
@use('App\Services\Manutencao\HorasManutencao')
@use('App\Services\Manutencao\RelatorioHorasManutencao')
@php($totApontado = array_sum(array_column($aproveitamento, 'apontado')))
@php($totDisponivel = array_sum(array_column($aproveitamento, 'disponivel')))
<div class="rolagem">
<table class="tabela">
  <thead><tr><th>Mantenedor</th><th class="num">Total apontado</th><th class="num">Total disponível</th><th class="num">Aproveitamento</th></tr></thead>
  <tbody>
  @forelse ($aproveitamento as $l)
    <tr>
      <td>{{ $l['mantenedor']->nome }}</td>
      <td class="num">{{ HorasManutencao::hhmm($l['apontado']) }}</td>
      <td class="num">{{ HorasManutencao::hhmm($l['disponivel']) }}</td>
      <td class="num {{ $l['aproveitamento'] === null ? '' : ($l['aproveitamento'] > RelatorioHorasManutencao::META_APROVEITAMENTO ? 'aceitavel' : 'incomum') }}">
        {{ $l['aproveitamento'] === null ? '—' : number_format($l['aproveitamento'] * 100, 1, ',', '.').'%' }}</td>
    </tr>
  @empty
    <tr><td colspan="4" class="suave">Nenhum mantenedor cadastrado.</td></tr>
  @endforelse
  </tbody>
  <tfoot><tr><th>Total</th><th class="num">{{ HorasManutencao::hhmm($totApontado) }}</th><th class="num">{{ HorasManutencao::hhmm($totDisponivel) }}</th>
    <th class="num {{ $totDisponivel > 0 && $totApontado / $totDisponivel > RelatorioHorasManutencao::META_APROVEITAMENTO ? 'aceitavel' : 'incomum' }}">{{ $totDisponivel > 0 ? number_format($totApontado / $totDisponivel * 100, 1, ',', '.').'%' : '—' }}</th></tr></tfoot>
</table>
</div>
